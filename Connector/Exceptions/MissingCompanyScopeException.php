<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A query against a company-owned table did not constrain the company axis.
 *
 * This is a programming error, not a runtime condition: tenant scoping alone
 * is not isolation for these tables, and a query that omits the company would
 * read, update, or delete a sibling company's rows. See
 * docs/contracts/company-ownership.md.
 */
final class MissingCompanyScopeException extends \LogicException
{
    /**
     * @param  class-string  $model
     * @param  list<string>  $expectedColumns
     */
    public static function for(string $model, array $expectedColumns): self
    {
        $columns = implode(' or ', $expectedColumns);

        return new self(
            "[$model] is company-owned: a query must constrain [$columns] as well as tenant_id. "
            .'Use forCompany($tenantId, $companyEntityId), or state why the query may span companies '
            .'with withoutCompanyScope($reason). See docs/contracts/company-ownership.md.',
        );
    }

    /**
     * @param  class-string  $model
     */
    public static function forDerivedTable(string $model): self
    {
        return new self(
            "[$model] is company-owned and this query selects from a derived or raw table, "
            .'so the guard cannot tell which table a column refers to and refuses rather than guess. '
            .'Query the model directly, or state why this query may span companies with '
            .'withoutCompanyScope($reason). See docs/contracts/company-ownership.md.',
        );
    }
}
