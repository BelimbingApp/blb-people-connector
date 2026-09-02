<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use Illuminate\Database\Eloquent\Builder;

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
     * There is deliberately no skills() relation.
     *
     * A hasMany here constrains `category_id`, which is not Skill's company
     * column, so the relation needed an escape to run at all — and an escape
     * covers the whole query, including whatever a caller appends to it. That
     * was not theoretical: `$category->skills()->orWhere('id', $otherId)`
     * emitted `where category_id = ? and category_id is not null or id = ?`,
     * which carries no company predicate and no tenant predicate either, so it
     * read, updated and deleted rows belonging to another company *and* to
     * another tenant.
     *
     * Telling callers not to append an orWhere is a convention, and the whole
     * point of this guard is that the company axis is not a convention. So the
     * builder is not handed out at all. The two things callers actually did
     * with it are below, each pinned to this category's own tenant and company
     * and each returning a value rather than a query you can add to.
     *
     * If you need the rows themselves, write the pinned query where you need
     * it and own it there:
     * Skill::query()->forCompany($tenantId, $companyEntityId)->where('category_id', $id).
     */
    public function skillCount(): int
    {
        return $this->ownSkills()->count();
    }

    public function hasActiveSkills(): bool
    {
        return $this->ownSkills()->where('active', true)->exists();
    }

    /**
     * Skills in this category, pinned to the category's own tenant and
     * company. Private, and consumed by the callers above in the same
     * expression, so there is no builder for anyone else to widen.
     *
     * @return Builder<Skill>
     */
    private function ownSkills(): Builder
    {
        return Skill::query()
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)
            ->where('category_id', $this->getKey());
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_category', 'id' => $this->getKey()];
    }
}
