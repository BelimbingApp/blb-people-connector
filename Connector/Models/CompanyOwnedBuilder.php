<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query builder every CompanyOwned model uses.
 *
 * RequireCompanyScope checks the predicates of a query. This checks its
 * *values*: a builder update() that sets the owning column is refused unless
 * the caller has stated why with movingCompany($reason). Model events cannot
 * do this — Eloquent's Builder::update() fires none — which is exactly the
 * path a pinned `->forCompany(...)->update(['company_entity_id' => ...])`
 * takes.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class CompanyOwnedBuilder extends Builder
{
    private ?string $companyMoveReason = null;

    /**
     * Deliberately change which company owns the affected rows.
     *
     * Like withoutCompanyScope(), the reason is the point: `grep -rn
     * movingCompany` is the complete list of places a row may change company.
     */
    public function movingCompany(string $reason): static
    {
        if (trim($reason) === '') {
            throw CompanyMoveRefusedException::emptyReason();
        }

        $this->companyMoveReason = $reason;

        return $this;
    }

    public function update(array $values): int
    {
        $this->refuseUnstatedMove(array_keys($values));

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($values !== []) {
            $columns = $update === null
                ? array_keys(reset($values))
                : array_map(fn ($key, $value) => is_int($key) ? $value : $key, array_keys($update), $update);

            $this->refuseUnstatedMove($columns);
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  list<mixed>  $columns
     */
    private function refuseUnstatedMove(array $columns): void
    {
        if ($this->companyMoveReason !== null) {
            return;
        }

        $model = $this->getModel();
        $owner = $model->companyOwnerColumn();

        if ($owner === null) {
            return;
        }

        foreach ($columns as $column) {
            if (! is_string($column)) {
                continue;
            }

            $bare = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

            if (trim($bare, " \t`\"[]") === $owner) {
                throw CompanyMoveRefusedException::for($model::class, $owner);
            }
        }
    }
}
