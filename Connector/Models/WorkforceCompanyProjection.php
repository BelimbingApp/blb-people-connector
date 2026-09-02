<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

/**
 * The current facts about one workforce company.
 *
 * This projection *is* a company, so its own workforce entity id is the
 * company axis; there is no separate company_entity_id column and there should
 * not be one.
 */
final class WorkforceCompanyProjection extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_connector_workforce_companies';

    public function companyOwnerColumn(): ?string
    {
        return 'workforce_entity_id';
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
