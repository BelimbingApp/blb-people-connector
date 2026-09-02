<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A write tried to change which company owns a row without saying so.
 *
 * The company scope guard proves a query names a company; it cannot stop an
 * already-pinned write from setting the owning column to a sibling company's
 * id, and the database will not either — that id is a real entity in the same
 * tenant, so the composite foreign key is satisfied. The row simply leaves,
 * and afterwards looks like data that was never there. So changing the owner
 * column is refused unless the writer states why with movingCompany($reason).
 * See docs/contracts/company-ownership.md.
 */
final class CompanyMoveRefusedException extends \LogicException
{
    public static function for(string $model, string $column): self
    {
        return new self(
            "{$model} refuses to change its owning column [{$column}]: the row would leave its company. "
            .'A sync pass or an entity merge that legitimately moves it must say so with '
            .'movingCompany($reason). See docs/contracts/company-ownership.md.',
        );
    }

    public static function emptyReason(): self
    {
        return new self('movingCompany() requires a stated reason why this write may change the owning company.');
    }
}
