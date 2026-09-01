<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
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
     * The escape is on the relation, not on its callers, and it is what makes
     * has()/whereHas()/withCount()/doesntHave() usable here. Those build a
     * correlated subquery whose only link to the parent is a column-to-column
     * predicate, which the company guard cannot read as a pin — so without
     * this a good-faith author counting levels would be pushed into writing
     * their own withoutCompanyScope() at the call site, manufacturing exactly
     * the unexamined hole this guard exists to prevent. Correlation to a scale
     * row the outer query already resolved is the reason it is safe, and it is
     * stated once, here.
     *
     * @return HasMany<ProficiencyScaleLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ProficiencyScaleLevel::class, 'scale_id')
            ->withoutCompanyScope('Levels are only ever reached correlated to one scale, whose company governed the query that produced it.')
            ->orderBy('level');
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
}
