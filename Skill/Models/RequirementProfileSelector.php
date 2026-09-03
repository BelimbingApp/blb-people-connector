<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;

/**
 * One target selector for a requirement profile. Selectors determine which
 * employee cohort the profile applies to. A selector has explicit tenant_id
 * and company_entity_id for isolation, copied from its profile.
 */
class RequirementProfileSelector extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_profile_selectors';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('selector_entity_id', WorkforceResourceType::OrganizationUnit),
            new WorkforceReference('selector_entity_id', WorkforceResourceType::Position),
        ];
    }

    protected function casts(): array
    {
        return [
            'selector_type' => SelectorType::class,
        ];
    }

    protected static function booted(): void
    {
        $guard = function (RequirementProfileSelector $selector): void {
            if ($selector->isCarriedByCompanyMerge() || $selector->isCarriedByWorkforceEntityMerge()) {
                return;
            }

            $profile = $selector->owningProfile();

            if ($profile !== null && $profile->isLocked()) {
                throw new PublishedRequirementImmutableException(
                    "Requirement profile {$profile->getKey()} is {$profile->status->value}; its selectors cannot change. Draft a new version instead.",
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * A company merge rewrites company_entity_id (and nothing else) when the
     * superseded company is already marked merged into the survivor.
     */
    private function isCarriedByCompanyMerge(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['company_entity_id'], $dirty['updated_at']);

        if ($dirty !== [] || ! $this->isDirty('company_entity_id')) {
            return false;
        }

        $originalId = $this->getOriginal('company_entity_id');
        if ($originalId === null || $this->company_entity_id === null) {
            return false;
        }

        return WorkforceEntity::query()
            ->forTenant((int) $this->tenant_id)
            ->whereKey((int) $originalId)
            ->where('state', WorkforceEntity::STATE_MERGED)
            ->where('merged_into_entity_id', (int) $this->company_entity_id)
            ->exists();
    }

    /**
     * A workforce-entity merge rewrites selector_entity_id (and nothing else)
     * when the superseded entity is already marked merged into the survivor.
     */
    private function isCarriedByWorkforceEntityMerge(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['selector_entity_id'], $dirty['updated_at']);

        if ($dirty !== [] || ! $this->isDirty('selector_entity_id')) {
            return false;
        }

        $originalId = $this->getOriginal('selector_entity_id');
        if ($originalId === null || $this->selector_entity_id === null) {
            return false;
        }

        return WorkforceEntity::query()
            ->forTenant((int) $this->tenant_id)
            ->whereKey((int) $originalId)
            ->where('state', WorkforceEntity::STATE_MERGED)
            ->where('merged_into_entity_id', (int) $this->selector_entity_id)
            ->exists();
    }

    /**
     * The owning profile, as a model — deliberately not a relation.
     * Pin tenant and company to prevent cross-company profile access.
     */
    private function owningProfile(): ?RequirementProfile
    {
        $tenantId = $this->tenant_id;
        $companyEntityId = $this->company_entity_id;

        if ($tenantId === null || $companyEntityId === null) {
            return null;
        }

        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereKey($this->profile_id)
            ->first();
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'requirement_profile_selector', 'id' => $this->getKey()];
    }
}
