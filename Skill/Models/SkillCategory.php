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

    /**
     * The escape is needed because this constrains `category_id`, which is not
     * Skill's company column. It is safe for the relation as written: the
     * category was resolved for its company, and the store refuses a skill
     * whose category belongs to another one — though the database does not.
     *
     * The escape covers whatever a caller appends to this relation, including
     * an unbracketed orWhere. Do not append one. Pin the company explicitly
     * instead: Skill::query()->forCompany(...)->where('category_id', ...).
     *
     * @return HasMany<Skill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class, 'category_id')
            ->withoutCompanyScope('Constrains category_id, which is not the skill company column; the category was resolved for its company and the store refuses a cross-company link.');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_category', 'id' => $this->getKey()];
    }
}
