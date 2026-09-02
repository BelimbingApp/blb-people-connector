<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The base query builder under every CompanyOwned model.
 *
 * RequireCompanyScope checks a query's predicates. This checks its values: a
 * write that sets one of the model's company columns is refused unless the
 * caller has stated why with movingCompany($reason), and that statement
 * covers exactly one write.
 *
 * It lives here, on the *base* builder, and not on the Eloquent builder,
 * because that is where every write actually lands. Eloquent's update() is
 * `toBase()->update()`; increment(), decrement(), incrementEach() and
 * decrementEach() are `toBase()->…` too, and their $extra argument is an
 * arbitrary column map spliced into the same SET; updateOrInsert() is not
 * defined on the Eloquent builder at all and is forwarded here by __call.
 * All of those end in update() or upsert() below. An Eloquent-level check saw
 * none of them (blb-people-connector#28, review at 8e9b5a3). Living here also
 * means ->getQuery() and ->toBase() do not step around it, and neither do
 * saveQuietly() or Model::withoutEvents(), because nothing here is an event.
 */
final class CompanyOwnedQuery extends QueryBuilder
{
    /** @var class-string */
    private string $model = '';

    /** @var list<string> */
    private array $companyColumns = [];

    private ?CompanyMoveGrant $grant = null;

    /**
     * @param  class-string  $model
     * @param  list<string>  $companyColumns
     */
    public function guardingCompanyColumns(string $model, array $companyColumns): static
    {
        $this->model = $model;
        $this->companyColumns = $companyColumns;

        return $this;
    }

    /**
     * Deliberately change the company columns of the affected rows, once.
     *
     * The reason is the point: `grep -rn movingCompany` is the complete list
     * of places a row may leave its company, with the author's justification
     * beside each. The grant covers the next update()/upsert() on this
     * builder or any clone of it, and nothing after that.
     */
    public function movingCompany(string $reason): static
    {
        $this->grant = new CompanyMoveGrant($reason);

        return $this;
    }

    /**
     * Hand an existing grant to this builder (a model passing its own).
     */
    public function withCompanyMoveGrant(CompanyMoveGrant $grant): static
    {
        $this->grant = $grant;

        return $this;
    }

    public function update(array $values)
    {
        $this->refuseUnstatedMove(array_keys($values));

        return parent::update($values);
    }

    public function updateFrom(array $values)
    {
        $this->refuseUnstatedMove(array_keys($values));

        return parent::updateFrom($values);
    }

    /**
     * Refused by shape, not by branch: whether the row exists decides between
     * INSERT and UPDATE at run time, and an author cannot state a move for
     * only one of those. A callable $values reaches update() and is checked
     * there; an array is checked here so the insert branch cannot be the one
     * that happens to run in a test and the update branch the one in prod.
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        if (is_array($values)) {
            $this->refuseUnstatedMove(array_keys($values), consume: false);
        }

        return parent::updateOrInsert($attributes, $values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($values !== []) {
            // Laravel accepts one flat row; normalise before reading keys, as
            // the parent does after us.
            if (! is_array(reset($values))) {
                $values = [$values];
            }

            $columns = $update === null
                ? array_keys(reset($values))
                : array_map(fn ($key, $value) => is_int($key) ? $value : $key, array_keys($update), $update);

            $this->refuseUnstatedMove($columns);
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Consumes the grant whether or not this write touches a company column:
     * "the next write" is a rule an author can hold in their head, "the next
     * write that happens to move something" is not.
     *
     * @param  list<mixed>  $columns
     */
    private function refuseUnstatedMove(array $columns, bool $consume = true): void
    {
        if ($consume) {
            $reason = $this->grant?->consume();
            $this->grant = null;
        } else {
            $reason = $this->grant?->isArmed() ? '' : null;
        }

        if ($reason !== null || $this->companyColumns === []) {
            return;
        }

        foreach ($columns as $column) {
            if (! is_string($column)) {
                continue;
            }

            $bare = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

            if (in_array(trim($bare, " \t`\"[]"), $this->companyColumns, true)) {
                throw CompanyMoveRefusedException::for($this->model, $bare);
            }
        }
    }
}
