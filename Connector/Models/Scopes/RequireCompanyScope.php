<?php

namespace App\Domains\PeopleConnector\Connector\Models\Scopes;

use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
        $baseTables = $this->baseTableNames($query->from, $model);

        if ($baseTables === null) {
            throw MissingCompanyScopeException::forDerivedTable($model::class);
        }

        if ($columns === [] || ! $this->pinsACompany($query, $columns, $baseTables)) {
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
     * A correlation to an enclosing query counts as well — see
     * correlatesToAnOuterQuery().
     *
     * @param  list<string>  $columns
     * @param  list<string>  $baseTables
     */
    private function pinsACompany(QueryBuilder $query, array $columns, array $baseTables): bool
    {
        $wheres = $query->wheres ?? [];

        foreach ($wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }
        }

        $localTables = $this->localTableNames($query, $baseTables);

        foreach ($wheres as $where) {
            if ($this->comparesToARealValue($where)
                && $this->addressesOwningColumn($where['column'], $columns, $baseTables)) {
                return true;
            }

            if ($localTables !== null && $this->correlatesToAnOuterQuery($where, $columns, $baseTables, $localTables)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A correlated subquery pins each row to exactly one row of an enclosing
     * query, which is the same strength as pinning to one literal parent id —
     * so it counts.
     *
     * This is what makes has(), whereHas(), withCount() and doesntHave() usable
     * on a company-owned model. Laravel links those to the parent with a
     * column-to-column predicate and nothing else, so without this the guard
     * refuses a subquery whose parent is perfectly well pinned. That is
     * fail-closed and leaks nothing, but it leaves a good-faith author no way
     * to satisfy the guard, and the next thing they reach for is
     * withoutCompanyScope() at their own call site — an escape wide enough to
     * cover whatever they append to it, reviewed by nobody. A guard that has to
     * be switched off to do ordinary work gets switched off.
     *
     * The other side must be resolvable **only** by an enclosing query: not the
     * base table, not a joined table, not unqualified. That distinction is
     * load-bearing. A column-to-column predicate against a table this query can
     * see is a join condition, not a pin — it constrains the company to
     * whatever the joined row happens to carry, which is how a join read a
     * sibling company's rows in the first place.
     *
     * @param  array<string, mixed>  $where
     * @param  list<string>  $columns
     * @param  list<string>  $baseTables
     * @param  list<string>  $localTables
     */
    private function correlatesToAnOuterQuery(array $where, array $columns, array $baseTables, array $localTables): bool
    {
        if (($where['type'] ?? null) !== 'Column' || ($where['operator'] ?? '=') !== '=') {
            return false;
        }

        $first = $where['first'] ?? null;
        $second = $where['second'] ?? null;

        if (! is_string($first) || ! is_string($second)) {
            return false;
        }

        foreach ([[$first, $second], [$second, $first]] as [$owning, $other]) {
            if (! $this->addressesOwningColumn($owning, $columns, $baseTables)) {
                continue;
            }

            $qualifier = $this->qualifierOf($other);

            if ($qualifier !== null && ! in_array($qualifier, $localTables, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every table name this query can resolve on its own: the base table and
     * everything it joins. Null when a join's table is an expression, because
     * then the list cannot be trusted to be complete and a correlation cannot
     * be told apart from a join condition.
     *
     * @param  list<string>  $baseTables
     * @return list<string>|null
     */
    private function localTableNames(QueryBuilder $query, array $baseTables): ?array
    {
        $names = $baseTables;

        foreach ($query->joins ?? [] as $join) {
            $table = $join->table ?? null;

            if (! is_string($table)) {
                return null;
            }

            if (preg_match('/^(.+?)\s+as\s+(.+)$/i', trim($table), $aliased) === 1) {
                $names[] = $this->unquote($aliased[1]);
                $names[] = $this->unquote($aliased[2]);
            } else {
                $names[] = $this->unquote($table);
            }
        }

        return array_values(array_unique($names));
    }

    private function qualifierOf(string $column): ?string
    {
        $column = $this->unquote($column);
        $separator = strrpos($column, '.');

        return $separator === false ? null : $this->unquote(substr($column, 0, $separator));
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
     * Every name a qualified column may legitimately use for the base table,
     * or null when the query's `from` makes that unknowable.
     *
     * Only names the query itself binds to the base table count. An earlier
     * version also trusted the model's table name unconditionally, which a
     * reviewer defeated with no raw SQL at all: aliasing `from` to something
     * else leaves the bare table name free, and a join can then take it —
     * `->from("skills as s")->join("categories as skills", …)` made
     * `skills.company_entity_id` a predicate on the *categories* table while
     * the guard still read it as the base table. So when `from` carries an
     * alias, only the alias is accepted. That also matches SQL, where
     * `skills.col` is no longer addressable once `skills` has been aliased.
     *
     * A non-string `from` — `fromSub()`, `fromRaw()`, any Expression — means
     * the base relation is derived and this scope cannot tell what a column
     * refers to. That fails closed rather than narrowing the accepted list in
     * silence, because `Skill::query()->fromSub(…)` reads to an author like it
     * is still inside the guarded model.
     *
     * @return list<string>|null
     */
    private function baseTableNames(mixed $from, Model $model): ?array
    {
        if (! is_string($from)) {
            return null;
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', trim($from), $aliased) === 1) {
            return [$this->unquote($aliased[2])];
        }

        $names = [$this->unquote($from)];

        // Belt and braces: an unaliased `from` should already be the model's
        // table, and this keeps qualifyColumn() working if it ever is not.
        if ($this->unquote($from) === $model->getTable()) {
            $names[] = $model->getTable();
        }

        return array_values(array_unique($names));
    }

    private function unquote(string $name): string
    {
        return trim($name, " \t`\"[]");
    }
}
