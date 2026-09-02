<?php

namespace App\Domains\PeopleConnector\Connector\Models\Concerns;

use App\Domains\PeopleConnector\Connector\Models\CompanyMoveGrant;
use App\Domains\PeopleConnector\Connector\Models\CompanyOwnedQuery;
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
    private ?CompanyMoveGrant $companyMoveGrant = null;

    private ?CompanyMoveGrant $pendingCompanyMoveGrant = null;

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
     * Every query on this model writes through CompanyOwnedQuery, which
     * refuses to change the columns above unless the write was stated with
     * movingCompany($reason). See that class for why the check lives on the
     * base builder and not here.
     *
     * `Model::query()->movingCompany('…')` reaches it through the Eloquent
     * builder's method forwarding.
     */
    protected function newBaseQueryBuilder(): CompanyOwnedQuery
    {
        $connection = $this->getConnection();

        return (new CompanyOwnedQuery($connection, $connection->getQueryGrammar(), $connection->getPostProcessor()))
            ->guardingCompanyColumns(static::class, $this->companyScopeColumns());
    }

    /**
     * Deliberately change which company this row belongs to on its next save.
     *
     * The statement covers exactly that one save() — consumed when the save
     * begins, whether it then succeeds, aborts at the database, or is stopped
     * by another listener — and a delete() does not spend it.
     */
    public function movingCompany(string $reason): static
    {
        $this->companyMoveGrant = new CompanyMoveGrant($reason);

        return $this;
    }

    public function save(array $options = []): bool
    {
        $grant = $this->companyMoveGrant;
        $this->companyMoveGrant = null;
        $this->pendingCompanyMoveGrant = $grant;

        try {
            return parent::save($options);
        } finally {
            $this->pendingCompanyMoveGrant = null;
        }
    }

    /**
     * The UPDATE a save() issues runs through the same base builder as every
     * other write, so the grant stated on the model is handed to that query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        if ($this->pendingCompanyMoveGrant !== null) {
            $query->withCompanyMoveGrant($this->pendingCompanyMoveGrant);
        }

        return parent::setKeysForSaveQuery($query);
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
     *
     * The list stays complete because CompanyIsolationContract fails the suite
     * if anything outside this method reaches for Laravel's own
     * withoutGlobalScope(), which removes the guard just as effectively and
     * says nothing about why.
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
