<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One behavioural anchor on a proficiency scale. `anchor` describes the
 * observable behaviour; `authority` states the work-alone / training / sign-off
 * authority the level carries. Levels of a non-draft scale are immutable.
 */
class ProficiencyScaleLevel extends TenantOwnedModel
{
    protected $table = 'people_connector_skill_proficiency_scale_levels';

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

    /** @return BelongsTo<ProficiencyScale, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(ProficiencyScale::class, 'scale_id');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'proficiency_scale_level', 'id' => $this->getKey()];
    }
}
