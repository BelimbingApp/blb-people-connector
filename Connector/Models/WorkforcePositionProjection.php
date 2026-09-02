<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

final class WorkforcePositionProjection extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_connector_workforce_positions';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('organization_entity_id', WorkforceResourceType::OrganizationUnit),
        ];
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
