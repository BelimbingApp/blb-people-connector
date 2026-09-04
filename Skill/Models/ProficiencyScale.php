<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned proficiency scale. Once published, the scale and its levels are
 * immutable at the model layer — historical scores must keep their meaning —
 * and the only permitted transition is published → retired. Changes are made
 * by drafting a new version.
 */
class ProficiencyScale extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_proficiency_scales';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ProficiencyScaleStatus::class,
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProficiencyScale $scale): void {
            $original = $scale->getOriginal('status');
            $original = $original instanceof ProficiencyScaleStatus
                ? $original
                : ProficiencyScaleStatus::from((string) $original);

            if ($original === ProficiencyScaleStatus::Draft) {
                return;
            }

            if ($original === ProficiencyScaleStatus::Published && $scale->isRetireOnlyChange()) {
                return;
            }

            if ($scale->isCarriedByCompanyMerge()) {
                return;
            }

            throw new PublishedScaleImmutableException(
                "Proficiency scale {$scale->getKey()} is {$original->value} and cannot be modified; draft a new version instead.",
            );
        });

        static::deleting(function (ProficiencyScale $scale): void {
            if ($scale->published_at !== null) {
                throw new PublishedScaleImmutableException(
                    "Proficiency scale {$scale->getKey()} has been published and cannot be deleted.",
                );
            }
        });
    }

    /**
     * No escape here, deliberately. A loaded relation constrains `scale_id` to
     * a real value, and has()/whereHas()/withCount()/doesntHave() correlate to
     * the parent's key — both of which the guard now reads as a pin on its own.
     * An escape would have covered whatever a caller appended to the relation,
     * including an unbracketed orWhere, which is the footgun the guard's
     * first rule exists to catch.
     *
     * @return HasMany<ProficiencyScaleLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ProficiencyScaleLevel::class, 'scale_id')->orderBy('level');
    }

    public function isLocked(): bool
    {
        return $this->status !== ProficiencyScaleStatus::Draft;
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'proficiency_scale', 'id' => $this->getKey()];
    }

    /**
     * True when the pending update only performs the published → retired
     * transition (status + retired_at), leaving every meaning-bearing field
     * untouched.
     */
    private function isRetireOnlyChange(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['status'], $dirty['retired_at'], $dirty['updated_at']);

        return $dirty === [] && $this->status === ProficiencyScaleStatus::Retired;
    }

    /**
     * A company merge changes the owner of a non-draft scale and nothing
     * else, and only from an entity already recorded as merged into the new
     * owner. The database trigger applies the same rule; this is the message
     * before the abort.
     */
    private function isCarriedByCompanyMerge(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['company_entity_id'], $dirty['updated_at']);

        if ($dirty !== [] || $this->isDirty('company_entity_id') === false) {
            return false;
        }

        return WorkforceEntity::query()
            ->forTenant((int) $this->tenant_id)
            ->whereKey((int) $this->getOriginal('company_entity_id'))
            ->where('state', WorkforceEntity::STATE_MERGED)
            ->where('merged_into_entity_id', (int) $this->company_entity_id)
            ->exists();
    }
}
