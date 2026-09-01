<?php

namespace App\Domains\PeopleConnector\Connector\Models\Scopes;

use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Refuses to run a query against a company-owned table unless the query pins
 * the company axis *on that table*.
 *
 * Laravel applies global scopes immediately before a query executes, so by the
 * time this runs every predicate the caller added is visible. Reads, updates,
 * deletes and mass inserts all pass through here. Creating a row through
 * Model::create() or $model->save() does not — Eloquent builds those without
 * scopes — so the database's NOT NULL and composite foreign key are the
 * backstop there. See docs/contracts/company-ownership.md.
 *
 * The three rules below each close a bypass that was reproduced end to end
 * against an earlier version of this file (blb-people-connector#10, review by
 * opus-5-review-j). They are strict on purpose: a guard that can be talked
 * into passing is worse than no guard, because it licenses confidence.
 */
final class RequireCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var list<string> $columns */
        $columns = $model->companyScopeColumns();
        $query = $builder->getQuery();

        if ($columns === [] || ! $this->pinsACompany($query->wheres ?? [], $columns, $this->baseTableNames($builder, $model))) {
            throw MissingCompanyScopeException::for($model::class, $columns);
        }
    }

    /**
     * A query pins a company when one of the owning columns, **on the base
     * table**, is compared to a real value at the top level of the query,
     * joined with AND.
     *
     * Deliberately strict on three counts:
     *
     *  - a predicate nested inside an `orWhere` group does not count, and a
     *    top-level `orWhere` anywhere disqualifies the query outright, because
     *    either can widen the result set past the company;
     *  - a qualified column must name the base table or its alias. Accepting
     *    any qualifier let a join whose ON clause correlated only on
     *    `tenant_id` satisfy the guard with a predicate on the *joined* table,
     *    leaving the base table unconstrained — reproduced as a read, a
     *    rename, and a delete of a sibling company's row;
     *  - the compared value must be a real value. A raw expression can be a
     *    tautology (`company_entity_id = company_entity_id`), and Laravel
     *    records a `whereIn` subquery as an ordinary `In` holding a single
     *    Expression, so an unbounded subquery would otherwise read as a pin.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     * @param  list<string>  $columns
     * @param  list<string>  $baseTables
     */
    private function pinsACompany(array $wheres, array $columns, array $baseTables): bool
    {
        foreach ($wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }
        }

        foreach ($wheres as $where) {
            if (! $this->comparesToARealValue($where)) {
                continue;
            }

            if ($this->addressesOwningColumn($where['column'], $columns, $baseTables)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function comparesToARealValue(array $where): bool
    {
        if (! is_string($where['column'] ?? null)) {
            return false;
        }

        // InRaw is what Laravel emits for an integer key list, which is how an
        // eager load constrains its foreign key.
        return match ($where['type'] ?? null) {
            'Basic' => ($where['operator'] ?? '=') === '=' && $this->isRealValue($where['value'] ?? null),
            'In', 'InRaw' => $this->areRealValues($where['values'] ?? null),
            default => false,
        };
    }

    private function isRealValue(mixed $value): bool
    {
        return $value !== null && ! $value instanceof Expression;
    }

    private function areRealValues(mixed $values): bool
    {
        if (! is_array($values) || $values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (! $this->isRealValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $baseTables
     */
    private function addressesOwningColumn(string $column, array $columns, array $baseTables): bool
    {
        $column = $this->unquote($column);
        $separator = strrpos($column, '.');

        if ($separator !== false) {
            $qualifier = $this->unquote(substr($column, 0, $separator));

            if (! in_array($qualifier, $baseTables, true)) {
                return false;
            }

            $column = $this->unquote(substr($column, $separator + 1));
        }

        return in_array($column, $columns, true);
    }

    /**
     * Every name a qualified column may legitimately use for the base table:
     * the model's table, the query's `from`, and the alias when `from` carries
     * one. A join's table is deliberately absent.
     *
     * @return list<string>
     */
    private function baseTableNames(Builder $builder, Model $model): array
    {
        $names = [$model->getTable()];
        $from = $builder->getQuery()->from;

        if (is_string($from)) {
            if (preg_match('/^(.+?)\s+as\s+(.+)$/i', trim($from), $aliased) === 1) {
                $names[] = $aliased[1];
                $names[] = $aliased[2];
            } else {
                $names[] = $from;
            }
        }

        return array_values(array_unique(array_map($this->unquote(...), $names)));
    }

    private function unquote(string $name): string
    {
        return trim($name, " \t`\"[]");
    }
}
