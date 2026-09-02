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
     * @param  bool  $forCompanyIsAvailable  false for a Class D table, which
     *                                       inherits its company from a parent
     *                                       row and has no company column of
     *                                       its own to pin
     */
    public static function for(string $model, array $expectedColumns, bool $forCompanyIsAvailable = true): self
    {
        $columns = implode(' or ', $expectedColumns);

        // Class D models throw a LogicException out of forCompany() telling the
        // author not to call it, so sending them there would be advice that
        // contradicts itself one line later. The right answer for them is the
        // column this message already names.
        $remedy = $forCompanyIsAvailable
            ? 'Use forCompany($tenantId, $companyEntityId)'
            : "This table inherits its company from a parent row, so forCompany() does not apply to it: constrain [$columns] to a parent you resolved for your own company";

        return new self(
            "[$model] is company-owned: a query must constrain [$columns] as well as tenant_id. "
            .$remedy.', or state why the query may span companies '
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

    /**
     * @param  class-string  $model
     */
    public static function forUnion(string $model): self
    {
        return new self(
            "[$model] is company-owned and this query carries a union, whose other arm is a separate "
            .'SELECT this guard never inspects: pinning the base does nothing to constrain it, and a '
            .'union arm reading the whole table returned every company and every tenant, hydrated as '
            ."[$model]. Run the arms as separate pinned queries and merge the results, or state why "
            .'this query may span companies with withoutCompanyScope($reason). '
            .'See docs/contracts/company-ownership.md.',
        );
    }
}
