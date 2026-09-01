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
}
