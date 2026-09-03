<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One skill requirement within a profile. Carries required proficiency level,
 * criticality/priority weight, evidence standard, and mandatory gate flag.
 * Items of a non-draft profile are immutable. A requirement item has explicit
 * tenant_id and company_entity_id for isolation, copied from its profile.
 */
class RequirementItem extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_items';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
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
            if ($item->isCarriedByCompanyMerge()) {
                return;
            }

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
        return ['name' => 'requirement_item', 'id' => $this->getKey()];
    }
}
