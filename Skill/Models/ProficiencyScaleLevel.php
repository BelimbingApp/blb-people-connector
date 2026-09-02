<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;

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
            $scale = $level->owningScale();

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
     * The owning scale, as a model — deliberately not a relation.
     *
     * A level carries `scale_id` and nothing else that names a company, so
     * walking back to the scale genuinely cannot be pinned from here: the
     * escape is unavoidable. What *was* avoidable is handing the escaped
     * builder to a caller. As a belongsTo relation this returned a live query
     * with the guard switched off, and anything appended to it inherited that
     * — `$level->scale()->orWhere(...)` read and wrote another company's
     * scale.
     *
     * So the escape stays where it is genuinely needed and the builder does
     * not leave this method. It is private, it is consumed in the same
     * expression, and it returns a model, so there is nothing to append to.
     */
    private function owningScale(): ?ProficiencyScale
    {
        return ProficiencyScale::query()
            ->withoutCompanyScope('A level names its scale only by the scale primary key, which is not the scale company column; the level itself was reached through a query already pinned to that scale.')
            ->whereKey($this->scale_id)
            ->first();
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'proficiency_scale_level', 'id' => $this->getKey()];
    }
}
