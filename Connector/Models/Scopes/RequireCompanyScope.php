<?php

namespace App\Domains\PeopleConnector\Connector\Models\Scopes;

use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Refuses to run a query against a company-owned table unless the query pins
 * the company axis.
 *
 * Laravel applies global scopes immediately before a query executes, so by the
 * time this runs every predicate the caller added is visible. Reads, updates,
 * deletes and mass inserts all pass through here. Creating a row through
 * Model::create() or $model->save() does not — Eloquent builds those without
 * scopes — so the database's NOT NULL and composite foreign key are the
 * backstop there. See docs/contracts/company-ownership.md.
 */
final class RequireCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var list<string> $columns */
        $columns = $model->companyScopeColumns();
        $wheres = $builder->getQuery()->wheres ?? [];

        if ($columns === [] || ! $this->pinsACompany($wheres, $columns)) {
            throw MissingCompanyScopeException::for($model::class, $columns);
        }
    }

    /**
     * A query pins a company when one of the owning columns is compared to a
     * value at the top level of the query, joined with AND.
     *
     * Deliberately strict. A predicate nested inside an `orWhere` group does
     * not count, and a top-level `orWhere` anywhere in the query disqualifies
     * it outright, because either can widen the result set past the company a
     * caller believes it scoped to.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     * @param  list<string>  $columns
     */
    private function pinsACompany(array $wheres, array $columns): bool
    {
        foreach ($wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }
        }

        foreach ($wheres as $where) {
            if (! $this->isPinningPredicate($where)) {
                continue;
            }

            if (in_array($this->unqualify($where['column']), $columns, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function isPinningPredicate(array $where): bool
    {
        $column = $where['column'] ?? null;

        if (! is_string($column)) {
            return false;
        }

        // InRaw is what Laravel emits for an integer key list, which is how
        // an eager load constrains its foreign key.
        return match ($where['type'] ?? null) {
            'Basic' => ($where['operator'] ?? '=') === '=' && ($where['value'] ?? null) !== null,
            'In', 'InRaw' => ($where['values'] ?? []) !== [],
            default => false,
        };
    }

    private function unqualify(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        return trim($bare, '`"[]');
    }
}
