<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfilePublished;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfileRetired;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidRequirementProfileException;
use App\Domains\PeopleConnector\Skill\Exceptions\RequirementProfileNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use Illuminate\Support\Facades\DB;

/**
 * Versioned requirement profile lifecycle: draft → published → retired.
 * Publishing retires the previously published version of the same code, so
 * exactly one version of a profile code is current per company. Published
 * profiles are immutable historical policy; employee movement does not rewrite
 * past requirements or assessments.
 */
class RequirementProfileStore
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,79}$/';
    private const WEIGHT_TOLERANCE = 0.0001;

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function draft(int $companyEntityId, RequirementProfileDraft $draft): RequirementProfile
    {
        $tenantId = $this->tenantContext->requireTenantId();

        if (preg_match(self::CODE_PATTERN, $draft->code) !== 1) {
            throw new InvalidRequirementProfileException(
                'A profile code must be 1-80 lowercase letters, digits, dots, dashes, or underscores, starting with a letter or digit.',
            );
        }

        $this->assertCompanyEntity($tenantId, $companyEntityId);
        $this->assertSelectors($tenantId, $draft->selectors);
        $this->assertItems($tenantId, $companyEntityId, $draft->items);

        if ($draft->ownerEmployeeEntityId !== null) {
            $this->assertEmployeeEntity($tenantId, $draft->ownerEmployeeEntityId);
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $draft): RequirementProfile {
            $currentMax = (int) RequirementProfile::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('code', $draft->code)
                ->max('version');

            if ($this->draftOf($tenantId, $companyEntityId, $draft->code) !== null) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$draft->code}] already has an open draft; publish or discard it before drafting again.",
                );
            }

            $profile = RequirementProfile::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'code' => $draft->code,
                'name' => $draft->name,
                'version' => $currentMax + 1,
                'status' => RequirementProfileStatus::Draft,
                'effective_date' => $draft->effectiveDate,
                'owner_employee_entity_id' => $draft->ownerEmployeeEntityId,
            ]);

            $this->writeSelectors($profile, $draft->selectors);
            $this->writeItems($profile, $draft->items);

            return $profile;
        });
    }

    public function newDraftFrom(int $companyEntityId, int $profileId): RequirementProfile
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $source = $this->requireProfile($tenantId, $companyEntityId, $profileId);

        $selectors = $source->selectors()->get()
            ->map(fn (RequirementProfileSelector $selector): RequirementSelectorDraft => new RequirementSelectorDraft(
                $selector->selector_type,
                $selector->selector_value,
                $selector->selector_entity_id,
            ))
            ->all();

        $items = $source->items()->get()
            ->map(fn (RequirementItem $item): RequirementItemDraft => new RequirementItemDraft(
                (int) $item->skill_id,
                (int) $item->sequence,
                (int) $item->required_level,
                $item->criticality,
                (float) $item->weight_percent,
                $item->evidence_standard,
                (bool) $item->mandatory_gate,
                $item->reassessment_months,
                (bool) $item->active,
            ))
            ->all();

        return $this->draft($companyEntityId, new RequirementProfileDraft(
            (string) $source->code,
            (string) $source->name,
            $selectors,
            $items,
            $source->effective_date,
            $source->owner_employee_entity_id,
        ));
    }

    public function publish(int $companyEntityId, int $profileId): RequirementProfile
    {
        $tenantId = $this->tenantContext->requireTenantId();

        return DB::transaction(function () use ($tenantId, $companyEntityId, $profileId): RequirementProfile {
            $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

            if ($profile->status !== RequirementProfileStatus::Draft) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only a draft can be published.",
                );
            }

            $items = $profile->items()->get();
            $this->assertPublishableItems($items->all());

            $previous = $this->publishedOf($tenantId, (int) $profile->company_entity_id, (string) $profile->code);
            $previous?->update([
                'status' => RequirementProfileStatus::Retired,
                'retired_at' => now(),
            ]);

            $profile->update([
                'status' => RequirementProfileStatus::Published,
                'published_at' => now(),
            ]);

            event(new RequirementProfilePublished(
                $tenantId,
                (int) $profile->getKey(),
                (string) $profile->code,
                (int) $profile->version,
                $previous?->getKey() === null ? null : (int) $previous->getKey(),
            ));

            return $profile;
        });
    }

    public function retire(int $companyEntityId, int $profileId): RequirementProfile
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

        if ($profile->status !== RequirementProfileStatus::Published) {
            throw new InvalidRequirementProfileException(
                "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only a published profile can be retired.",
            );
        }

        $profile->update([
            'status' => RequirementProfileStatus::Retired,
            'retired_at' => now(),
        ]);

        event(new RequirementProfileRetired(
            $tenantId,
            (int) $profile->getKey(),
            (string) $profile->code,
            (int) $profile->version,
        ));

        return $profile;
    }

    public function discardDraft(int $companyEntityId, int $profileId): void
    {
        $tenantId = $this->tenantContext->requireTenantId();

        DB::transaction(function () use ($tenantId, $companyEntityId, $profileId): void {
            $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

            if ($profile->status !== RequirementProfileStatus::Draft) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only a draft can be discarded.",
                );
            }

            $profile->delete();
        });
    }

    public function currentProfile(int $companyEntityId, string $code): ?RequirementProfile
    {
        return $this->publishedOf($this->tenantContext->requireTenantId(), $companyEntityId, $code);
    }

    private function requireProfile(int $tenantId, int $companyEntityId, int $profileId): RequirementProfile
    {
        return RequirementProfile::query()->forCompany($tenantId, $companyEntityId)->find($profileId)
            ?? throw new RequirementProfileNotFoundException("Requirement profile [$profileId] was not found.");
    }

    private function draftOf(int $tenantId, int $companyEntityId, string $code): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('code', $code)
            ->where('status', RequirementProfileStatus::Draft->value)
            ->first();
    }

    private function publishedOf(int $tenantId, int $companyEntityId, string $code): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('code', $code)
            ->where('status', RequirementProfileStatus::Published->value)
            ->first();
    }

    /**
     * @param list<RequirementSelectorDraft> $selectors
     */
    private function writeSelectors(RequirementProfile $profile, array $selectors): void
    {
        foreach ($selectors as $selector) {
            RequirementProfileSelector::query()->create([
                'tenant_id' => $profile->tenant_id,
                'profile_id' => $profile->getKey(),
                'selector_type' => $selector->selectorType,
                'selector_value' => $selector->selectorValue,
                'selector_entity_id' => $selector->selectorEntityId,
            ]);
        }
    }

    /**
     * @param list<RequirementItemDraft> $items
     */
    private function writeItems(RequirementProfile $profile, array $items): void
    {
        foreach ($items as $item) {
            RequirementItem::query()->create([
                'tenant_id' => $profile->tenant_id,
                'profile_id' => $profile->getKey(),
                'skill_id' => $item->skillId,
                'sequence' => $item->sequence,
                'required_level' => $item->requiredLevel,
                'criticality' => $item->criticality,
                'weight_percent' => $item->weightPercent,
                'evidence_standard' => $item->evidenceStandard,
                'mandatory_gate' => $item->mandatoryGate,
                'reassessment_months' => $item->reassessmentMonths,
                'active' => $item->active,
            ]);
        }
    }

    /**
     * @param list<RequirementSelectorDraft> $selectors
     */
    private function assertSelectors(int $tenantId, array $selectors): void
    {
        if (count($selectors) === 0) {
            throw new InvalidRequirementProfileException('A requirement profile needs at least one target selector.');
        }

        foreach ($selectors as $selector) {
            if ($selector->selectorType === SelectorType::Department && $selector->selectorEntityId === null) {
                throw new InvalidRequirementProfileException('Department selector requires a selector_entity_id.');
            }

            if ($selector->selectorEntityId !== null) {
                $entity = WorkforceEntity::query()->forTenant($tenantId)->find($selector->selectorEntityId);
                if ($entity === null) {
                    throw new InvalidRequirementProfileException(
                        "Selector entity [{$selector->selectorEntityId}] was not found.",
                    );
                }

                if ($selector->selectorType === SelectorType::Department
                    && $entity->resource_type !== WorkforceResourceType::OrganizationUnit->value) {
                    throw new InvalidRequirementProfileException(
                        'Department selector entity must be an organization_unit.',
                    );
                }
            }
        }
    }

    /**
     * @param list<RequirementItemDraft> $items
     */
    private function assertItems(int $tenantId, int $companyEntityId, array $items): void
    {
        if (count($items) === 0) {
            throw new InvalidRequirementProfileException('A requirement profile needs at least one skill requirement.');
        }

        $skillIds = array_map(fn (RequirementItemDraft $item): int => $item->skillId, $items);
        if (count(array_unique($skillIds)) !== count($skillIds)) {
            throw new InvalidRequirementProfileException('Each skill may appear only once in a requirement profile.');
        }

        $sequences = array_map(fn (RequirementItemDraft $item): int => $item->sequence, $items);
        sort($sequences);
        if ($sequences !== range(1, count($items))) {
            throw new InvalidRequirementProfileException('Requirement sequences must be contiguous and start at 1.');
        }

        foreach ($items as $item) {
            if ($item->requiredLevel < 0 || $item->requiredLevel > 5) {
                throw new InvalidRequirementProfileException('Required level must be between 0 and 5.');
            }

            if ($item->weightPercent < 0) {
                throw new InvalidRequirementProfileException('Weight percent must be non-negative.');
            }

            if ($item->reassessmentMonths !== null && $item->reassessmentMonths <= 0) {
                throw new InvalidRequirementProfileException('Reassessment months must be a positive whole number.');
            }

            $skill = Skill::query()->forCompany($tenantId, $companyEntityId)->find($item->skillId);
            if ($skill === null) {
                throw new InvalidRequirementProfileException(
                    "Skill [{$item->skillId}] was not found in this company.",
                );
            }

            if (! $skill->active) {
                throw new InvalidRequirementProfileException(
                    "Skill [{$item->skillId}] is inactive and cannot be added to a requirement profile.",
                );
            }
        }
    }

    /**
     * @param array<RequirementItem> $items
     */
    private function assertPublishableItems(array $items): void
    {
        $totalWeight = array_sum(array_map(
            fn (RequirementItem $item): float => (float) $item->weight_percent,
            $items,
        ));

        if (abs($totalWeight - 100.0) > self::WEIGHT_TOLERANCE) {
            throw new InvalidRequirementProfileException(
                'Requirement weights must total 100%. Current total: '.number_format($totalWeight, 2).'%',
            );
        }
    }

    private function assertCompanyEntity(int $tenantId, int $companyEntityId): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($companyEntityId);

        if ($entity === null || $entity->resource_type !== WorkforceResourceType::Company->value) {
            throw new InvalidRequirementProfileException(
                'A requirement profile must belong to an existing company workforce entity in this tenant.',
            );
        }
    }

    private function assertEmployeeEntity(int $tenantId, int $employeeEntityId): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($employeeEntityId);

        if ($entity === null || $entity->resource_type !== WorkforceResourceType::Employee->value) {
            throw new InvalidRequirementProfileException(
                'Profile owner must be an existing employee workforce entity in this tenant.',
            );
        }
    }
}
