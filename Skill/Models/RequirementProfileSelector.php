<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;

/**
 * One target selector for a requirement profile. Selectors determine which
 * employee cohort the profile applies to. A selector inherits its company
 * from its profile, so profile_id is what pins a query here.
 */
class RequirementProfileSelector extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_profile_selectors';

    public function companyOwnerColumn(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function companyScopeColumns(): array
    {
        return ['profile_id'];
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
     * A selector names its profile only by the profile's primary key, so the
     * escape here is unavoidable. The builder is consumed in the same
     * expression and returns a model, so there is nothing to append to.
     */
    private function owningProfile(): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->withoutCompanyScope('A selector names its profile only by the profile primary key, which is not the profile company column; the selector itself was reached through a query already pinned to that profile.')
            ->whereKey($this->profile_id)
            ->first();
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'requirement_profile_selector', 'id' => $this->getKey()];
    }
}
