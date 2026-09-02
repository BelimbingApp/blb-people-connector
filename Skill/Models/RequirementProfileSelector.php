<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;

/**
 * One target selector for a requirement profile. Selectors determine which
 * employee cohort the profile applies to. A selector has explicit tenant_id
 * and company_entity_id for isolation, copied from its profile.
 */
class RequirementProfileSelector extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_profile_selectors';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
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
