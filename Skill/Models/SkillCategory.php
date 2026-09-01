<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company-scoped grouping for skills. Company ownership uses the connector's
 * provider-neutral workforce entity id, never a provider table.
 */
class SkillCategory extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_categories';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Skill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class, 'category_id')
            ->withoutCompanyScope('Reached only from a category already resolved for its company; the store refuses a skill whose category belongs to another one.');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_category', 'id' => $this->getKey()];
    }
}
