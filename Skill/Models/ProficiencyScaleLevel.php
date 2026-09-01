<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One behavioural anchor on a proficiency scale. `anchor` describes the
 * observable behaviour; `authority` states the work-alone / training / sign-off
 * authority the level carries. Levels of a non-draft scale are immutable.
 *
 * A level inherits its company from its scale, so `scale_id` is what pins a
 * query here — see docs/contracts/company-ownership.md.
 */
class ProficiencyScaleLevel extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_proficiency_scale_levels';

    public function companyOwnerColumn(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function companyScopeColumns(): array
    {
        return ['scale_id'];
    }

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (ProficiencyScaleLevel $level): void {
            $scale = $level->scale()->first();

            if ($scale !== null && $scale->isLocked()) {
                throw new PublishedScaleImmutableException(
                    "Proficiency scale {$scale->getKey()} is {$scale->status->value}; its levels cannot change. Draft a new version instead.",
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * The escape is needed because a belongsTo constrains the parent's primary
     * key, which is not ProficiencyScale's company column. It is safe for the
     * relation as written: the level came from a query pinned to one scale,
     * and this walks back to that same scale.
     *
     * The escape covers whatever a caller appends to this relation, including
     * an unbracketed orWhere. Do not append one.
     *
     * @return BelongsTo<ProficiencyScale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(ProficiencyScale::class, 'scale_id')
            ->withoutCompanyScope('Constrains the scale primary key, which is not its company column; the level came from a query already pinned to that scale.');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'proficiency_scale_level', 'id' => $this->getKey()];
    }
}
