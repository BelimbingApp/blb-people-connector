<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidRequirementProfileException;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use DateTimeInterface;

/**
 * Resolves which requirement profile applies to an employee as of a date.
 * The resolver is deterministic, company/tenant safe, and returns an
 * explanation of the matching selectors.
 *
 * Also publishes the assessment-facing {@see ResolvesSkillRequirements} shape
 * so gap/assessment code never imports profile selectors (blb-people#80).
 */
class RequirementResolver implements ResolvesSkillRequirements
{
    /**
     * Resolve the active requirement profile for an employee at a given date.
     * Returns the profile and an explanation of how it matched.
     *
     * @param  array<string, mixed>  $employeeData  Employee attributes: company_entity_id, department_entity_id, position_entity_id
     * @return array{profile: RequirementProfile|null, explanation: string, matched_selectors: array<string>}
     */
    public function resolve(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $asOf = $asOf ?? now();

        $companyEntityId = $employeeData['company_entity_id'] ?? null;
        if ($companyEntityId === null) {
            return [
                'profile' => null,
                'explanation' => 'Employee has no company assignment.',
                'matched_selectors' => [],
            ];
        }

        $profiles = RequirementProfile::query()
            ->forCompany($tenantId, (int) $companyEntityId)
            ->whereIn('status', [RequirementProfileStatus::Published->value, RequirementProfileStatus::Retired->value])
            ->whereNotNull('published_at')
            // DATE() on both arms so SQLite's cast-date string ('Y-m-d H:i:s')
            // compares equal to the bare as-of day the same way PostgreSQL does.
            ->whereRaw('COALESCE(DATE(effective_date), DATE(published_at)) <= ?', [$asOf->format('Y-m-d')])
            // Single raw predicate keeps the company pin AND-only under
            // RequireCompanyScope (#65): a Nested whereNull/orWhereDate group
            // is an OR at depth and disqualifies the whole query. Safe here
            // because this OR is ANDed with forCompany()'s basic comparison on
            // company_entity_id — it can only narrow, never widen past the company.
            ->whereRaw('(retired_at IS NULL OR DATE(retired_at) > ?)', [$asOf->format('Y-m-d')])
            ->orderByRaw('COALESCE(DATE(effective_date), DATE(published_at)) DESC')
            ->orderBy('version', 'desc')
            ->get();

        $matchingProfiles = [];
        $bestFailureExplanation = null;
        $hadPartialMatch = false;

        foreach ($profiles as $profile) {
            $matchResult = $this->matchesProfile($profile, $employeeData);
            if ($matchResult['matches']) {
                $matchingProfiles[] = [
                    'profile' => $profile,
                    'explanation' => $matchResult['explanation'],
                    'matched_selectors' => $matchResult['matched_selectors'],
                ];
            } elseif ($matchResult['partial_match'] ?? false) {
                $hadPartialMatch = true;
                $bestFailureExplanation = $matchResult['explanation'];
            }
        }

        if (count($matchingProfiles) > 1) {
            $codes = array_map(fn ($m) => "[{$m['profile']->code}] v{$m['profile']->version}", $matchingProfiles);

            throw new InvalidRequirementProfileException(
                'Multiple published requirement profiles match this employee: '.implode(', ', $codes).'. '
                .'Overlapping profiles must be retired or refined to prevent ambiguity.'
            );
        }

        if (count($matchingProfiles) === 1) {
            $match = $matchingProfiles[0];

            return [
                'profile' => $match['profile'],
                'explanation' => "Matched profile [{$match['profile']->code}] v{$match['profile']->version}: {$match['explanation']}",
                'matched_selectors' => $match['matched_selectors'],
            ];
        }

        return [
            'profile' => null,
            'explanation' => $hadPartialMatch ? $bestFailureExplanation : 'No published requirement profile matches this employee\'s attributes.',
            'matched_selectors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return list<ResolvedSkillRequirement>
     */
    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        $resolved = $this->resolve($employeeData, $asOf);
        $profile = $resolved['profile'];

        if ($profile === null) {
            return [];
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $items = RequirementItem::query()
            ->forCompany($tenantId, (int) $profile->company_entity_id)
            ->where('profile_id', $profile->getKey())
            ->where('active', true)
            ->orderBy('sequence')
            ->get();

        $reference = (string) $profile->code;
        $version = (int) $profile->version;

        return $items->map(fn (RequirementItem $item): ResolvedSkillRequirement => new ResolvedSkillRequirement(
            requirementReference: $reference,
            requirementVersion: $version,
            skillId: (int) $item->skill_id,
            requiredLevel: (int) $item->required_level,
            criticality: $item->criticality,
            mandatoryGate: (bool) $item->mandatory_gate,
        ))->all();
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string, matched_selectors: array<string>, partial_match?: bool}
     */
    private function matchesProfile(RequirementProfile $profile, array $employeeData): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $selectors = RequirementProfileSelector::query()
            ->forCompany($tenantId, (int) $profile->company_entity_id)
            ->where('profile_id', $profile->getKey())
            ->get();
        if ($selectors->isEmpty()) {
            return [
                'matches' => false,
                'explanation' => 'Profile has no selectors.',
                'matched_selectors' => [],
                'partial_match' => false,
            ];
        }

        $matchedSelectors = [];
        $explanations = [];
        $selectorIndex = 0;

        foreach ($selectors as $selector) {
            $selectorMatch = $this->matchesSelector($selector, $employeeData);
            if ($selectorMatch['matches'] === false) {
                return [
                    'matches' => false,
                    'explanation' => "Failed on selector: {$selectorMatch['explanation']}",
                    'matched_selectors' => [],
                    'partial_match' => $selectorIndex > 0 || $selectors->count() > 1,
                ];
            }

            $matchedSelectors[] = $selector->selector_type->value;
            $explanations[] = $selectorMatch['explanation'];
            $selectorIndex++;
        }

        return [
            'matches' => true,
            'explanation' => implode(', ', $explanations),
            'matched_selectors' => $matchedSelectors,
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchesSelector(RequirementProfileSelector $selector, array $employeeData): array
    {
        return match ($selector->selector_type) {
            SelectorType::Company => [
                'matches' => true,
                'explanation' => 'Company-wide profile',
            ],
            SelectorType::Department => $this->matchDepartment($selector, $employeeData),
            SelectorType::Position => $this->matchPosition($selector, $employeeData),
        };
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchDepartment(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeeDepartmentId = $employeeData['department_entity_id'] ?? null;

        if ($selector->selector_entity_id === null) {
            return [
                'matches' => false,
                'explanation' => 'Department selector has no entity ID',
            ];
        }

        if ($employeeDepartmentId === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no department',
            ];
        }

        if ((int) $selector->selector_entity_id === (int) $employeeDepartmentId) {
            return [
                'matches' => true,
                'explanation' => "Department entity ID {$selector->selector_entity_id}",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee department {$employeeDepartmentId} does not match {$selector->selector_entity_id}",
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchPosition(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeePositionEntityId = $employeeData['position_entity_id'] ?? null;

        if ($selector->selector_entity_id === null) {
            return [
                'matches' => false,
                'explanation' => 'Position selector has no entity ID',
            ];
        }

        if ($employeePositionEntityId === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no position',
            ];
        }

        if ((int) $selector->selector_entity_id === (int) $employeePositionEntityId) {
            return [
                'matches' => true,
                'explanation' => "Position entity ID {$selector->selector_entity_id}",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee position {$employeePositionEntityId} does not match {$selector->selector_entity_id}",
        ];
    }
}
