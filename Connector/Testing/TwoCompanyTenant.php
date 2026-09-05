<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Core\Company\Models\Company;

/**
 * One tenant holding two companies — the shape every isolation test in this
 * repository was missing. Cross-tenant fixtures cannot see a company leak,
 * because both companies live on the same side of the tenant boundary.
 *
 * Each side carries both ids that the word "company" refers to here: the
 * platform company a user belongs to, and the workforce company entity that
 * connector workforce projections are owned by. See
 * docs/contracts/company-ownership.md.
 */
final readonly class TwoCompanyTenant
{
    public function __construct(
        public int $tenantId,
        public Company $alphaCompany,
        public int $alphaCompanyEntityId,
        public Company $betaCompany,
        public int $betaCompanyEntityId,
    ) {}
}
