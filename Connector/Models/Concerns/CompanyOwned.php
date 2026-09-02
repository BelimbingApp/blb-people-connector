<?php

namespace App\Domains\PeopleConnector\Connector\Models\Concerns;

use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Models\CompanyOwnedBuilder;
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
    private ?string $companyMoveReason = null;

    public static function bootCompanyOwned(): void
    {
        static::addGlobalScope(new RequireCompanyScope);

        // A row may not change company by accident. The guard above proves a
        // query names a company; it cannot see that a pinned write then sets
        // the owning column to a sibling's id, and neither can the database,
        // because that id is a real entity in the same tenant. So the change
        // is refused unless the writer said movingCompany($reason) first.
        static::updating(function (self $model): void {
            $column = $model->companyOwnerColumn();

            if ($column !== null && $model->isDirty($column) && $model->companyMoveReason === null) {
                throw CompanyMoveRefusedException::for(static::class, $column);
            }
        });

        static::saved(function (self $model): void {
            $model->companyMoveReason = null;
        });
    }

    /**
     * @return CompanyOwnedBuilder<static>
     */
    public function newEloquentBuilder($query): CompanyOwnedBuilder
    {
        return new CompanyOwnedBuilder($query);
    }

    /**
     * A save() runs its UPDATE through the same builder as everyone else, so a
     * reason stated on the model has to reach the query it issues.
     *
     * @param  CompanyOwnedBuilder<static>  $query
     * @return CompanyOwnedBuilder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        if ($this->companyMoveReason !== null) {
            $query->movingCompany($this->companyMoveReason);
        }

        return parent::setKeysForSaveQuery($query);
    }

    /**
     * Deliberately change which company owns this row on the next save.
     *
     * The reason is required for the same purpose as withoutCompanyScope():
     * `grep -rn movingCompany` is the complete list of places a row may leave
     * its company — today a sync pass writing the provider's payload, and an
     * entity merge rewriting references to the superseded company.
     */
    public function movingCompany(string $reason): static
    {
        if (trim($reason) === '') {
            throw CompanyMoveRefusedException::emptyReason();
        }

        $this->companyMoveReason = $reason;

        return $this;
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
