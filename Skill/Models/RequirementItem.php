<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One skill requirement within a profile. Carries required proficiency level,
 * criticality/priority weight, evidence standard, and mandatory gate flag.
 * Items of a non-draft profile are immutable. A requirement item inherits its
 * company from its profile, so profile_id is what pins a query here.
 */
class RequirementItem extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_items';

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
            'sequence' => 'integer',
            'required_level' => 'integer',
            'criticality' => RequirementCriticality::class,
            'weight_percent' => 'decimal:2',
            'mandatory_gate' => 'boolean',
            'reassessment_months' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (RequirementItem $item): void {
            $profile = $item->owningProfile();

            if ($profile !== null && $profile->isLocked()) {
                throw new PublishedRequirementImmutableException(
                    "Requirement profile {$profile->getKey()} is {$profile->status->value}; its items cannot change. Draft a new version instead.",
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * No escape here, deliberately. See Skill::category() for the full
     * explanation. Lazy loading will throw; eager load with the company pinned.
     *
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id');
    }

    /**
     * The owning profile, as a model — deliberately not a relation.
     * An item names its profile only by the profile's primary key, so the
     * escape here is unavoidable. The builder is consumed in the same
     * expression and returns a model, so there is nothing to append to.
     */
    private function owningProfile(): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->withoutCompanyScope('An item names its profile only by the profile primary key, which is not the profile company column; the item itself was reached through a query already pinned to that profile.')
            ->whereKey($this->profile_id)
            ->first();
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'requirement_item', 'id' => $this->getKey()];
    }
}
