<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use DateTimeInterface;

/**
 * Resolves which requirement profile applies to an employee as of a date.
 * The resolver is deterministic, company/tenant safe, and returns an
 * explanation of the matching selectors.
 */
class RequirementResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Resolve the active requirement profile for an employee at a given date.
     * Returns the profile and an explanation of how it matched.
     *
     * @param  array<string, mixed>  $employeeData  Employee attributes: company_entity_id, department_entity_id, job_title, job_grade, workforce_class, position
     * @return array{profile: RequirementProfile|null, explanation: string, matched_selectors: array<string>}
     */
    public function resolve(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
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
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_date')
                    ->orWhere('effective_date', '<=', $asOf);
            })
            ->orderBy('effective_date', 'desc')
            ->orderBy('version', 'desc')
            ->with('selectors')
            ->get();

        $bestFailureExplanation = null;
        $hadPartialMatch = false;

        foreach ($profiles as $profile) {
            $matchResult = $this->matchesProfile($profile, $employeeData);
            if ($matchResult['matches']) {
                return [
                    'profile' => $profile,
                    'explanation' => "Matched profile [{$profile->code}] v{$profile->version}: {$matchResult['explanation']}",
                    'matched_selectors' => $matchResult['matched_selectors'],
                ];
            }

            if ($matchResult['partial_match']) {
                $hadPartialMatch = true;
                $bestFailureExplanation = $matchResult['explanation'];
            }
        }

        return [
            'profile' => null,
            'explanation' => $hadPartialMatch ? $bestFailureExplanation : 'No published requirement profile matches this employee\'s attributes.',
            'matched_selectors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string, matched_selectors: array<string>, partial_match?: bool}
     */
    private function matchesProfile(RequirementProfile $profile, array $employeeData): array
    {
        $selectors = $profile->selectors;
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
            if (! $selectorMatch['matches']) {
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
            SelectorType::JobTitle => $this->matchJobTitle($selector, $employeeData),
            SelectorType::JobGrade => $this->matchJobGrade($selector, $employeeData),
            SelectorType::WorkforceClass => $this->matchWorkforceClass($selector, $employeeData),
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
    private function matchJobTitle(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeeJobTitle = $employeeData['job_title'] ?? null;

        if ($selector->selector_value === null) {
            return [
                'matches' => false,
                'explanation' => 'Job title selector has no value',
            ];
        }

        if ($employeeJobTitle === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no job title',
            ];
        }

        if (strcasecmp((string) $selector->selector_value, (string) $employeeJobTitle) === 0) {
            return [
                'matches' => true,
                'explanation' => "Job title '{$selector->selector_value}'",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee job title '{$employeeJobTitle}' does not match '{$selector->selector_value}'",
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchJobGrade(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeeJobGrade = $employeeData['job_grade'] ?? null;

        if ($selector->selector_value === null) {
            return [
                'matches' => false,
                'explanation' => 'Job grade selector has no value',
            ];
        }

        if ($employeeJobGrade === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no job grade',
            ];
        }

        if (strcasecmp((string) $selector->selector_value, (string) $employeeJobGrade) === 0) {
            return [
                'matches' => true,
                'explanation' => "Job grade '{$selector->selector_value}'",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee job grade '{$employeeJobGrade}' does not match '{$selector->selector_value}'",
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchWorkforceClass(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeeWorkforceClass = $employeeData['workforce_class'] ?? null;

        if ($selector->selector_value === null) {
            return [
                'matches' => false,
                'explanation' => 'Workforce class selector has no value',
            ];
        }

        if ($employeeWorkforceClass === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no workforce class',
            ];
        }

        if (strcasecmp((string) $selector->selector_value, (string) $employeeWorkforceClass) === 0) {
            return [
                'matches' => true,
                'explanation' => "Workforce class '{$selector->selector_value}'",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee workforce class '{$employeeWorkforceClass}' does not match '{$selector->selector_value}'",
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeData
     * @return array{matches: bool, explanation: string}
     */
    private function matchPosition(RequirementProfileSelector $selector, array $employeeData): array
    {
        $employeePosition = $employeeData['position'] ?? null;

        if ($selector->selector_value === null) {
            return [
                'matches' => false,
                'explanation' => 'Position selector has no value',
            ];
        }

        if ($employeePosition === null) {
            return [
                'matches' => false,
                'explanation' => 'Employee has no position',
            ];
        }

        if (strcasecmp((string) $selector->selector_value, (string) $employeePosition) === 0) {
            return [
                'matches' => true,
                'explanation' => "Position '{$selector->selector_value}'",
            ];
        }

        return [
            'matches' => false,
            'explanation' => "Employee position '{$employeePosition}' does not match '{$selector->selector_value}'",
        ];
    }
}
