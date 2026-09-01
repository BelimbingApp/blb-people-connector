<?php

namespace App\Domains\PeopleConnector\Connector\Models\Concerns;

use App\Domains\PeopleConnector\Connector\Models\Scopes\RequireCompanyScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks a model whose rows belong to one company inside the tenant, and makes
 * forgetting that boundary an error instead of a silent leak.
 *
 * A tenant normally contains several companies, so `forTenant()` on its own is
 * not isolation for these tables: it returns every company's rows. Using this
 * trait registers a guard that refuses to run any query which does not pin the
 * company axis, so the omission raises MissingCompanyScopeException rather
 * than quietly returning a sibling company's data.
 *
 * Three shapes are supported:
 *
 *  - a table carrying `company_entity_id` uses the defaults;
 *  - a table that *is* a company overrides companyOwnerColumn() to name its
 *    own identity column;
 *  - a table that inherits ownership from a parent (a scale's levels, a
 *    connection's checkpoints) returns null from companyOwnerColumn() and
 *    names the parent column in companyScopeColumns().
 *
 * See docs/contracts/company-ownership.md for the table-by-table rule.
 */
trait CompanyOwned
{
    public static function bootCompanyOwned(): void
    {
        static::addGlobalScope(new RequireCompanyScope);
    }

    /**
     * The column holding this row's owning company workforce entity id, or
     * null when ownership is inherited from a parent row instead.
     */
    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /**
     * The columns which, once constrained, pin a query to a single company.
     *
     * @return list<string>
     */
    public function companyScopeColumns(): array
    {
        $column = $this->companyOwnerColumn();

        return $column === null ? [] : [$column];
    }

    /**
     * Scope to one company inside one tenant. Both axes in one call, so a
     * query cannot be half-scoped.
     */
    public function scopeForCompany(Builder $query, int $tenantId, int $companyEntityId): void
    {
        $column = $this->companyOwnerColumn();

        if ($column === null) {
            throw new \LogicException(
                static::class.' inherits its company from a parent row; constrain ['
                .implode(' or ', $this->companyScopeColumns()).'] instead of calling forCompany().',
            );
        }

        $query->where($this->qualifyColumn('tenant_id'), $tenantId)
            ->where($this->qualifyColumn($column), $companyEntityId);
    }

    /**
     * Deliberately run a query that does not pin one company.
     *
     * The reason is required and is not decoration: `grep -rn
     * withoutCompanyScope` is the complete, reviewable list of every place the
     * company boundary is not applied, with the author's justification next to
     * it. That visibility is the whole point — the defect this guard exists to
     * stop was invisible precisely because the unscoped queries looked exactly
     * like the scoped ones.
     */
    public function scopeWithoutCompanyScope(Builder $query, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'withoutCompanyScope() requires a stated reason why this query may span companies.',
            );
        }

        $query->withoutGlobalScope(RequireCompanyScope::class);
    }
}
