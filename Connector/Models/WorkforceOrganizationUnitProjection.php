<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

final class WorkforceOrganizationUnitProjection extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_connector_workforce_organization_units';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('parent_entity_id', WorkforceResourceType::OrganizationUnit, hierarchy: true),
        ];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'privacy_deleted_at' => 'immutable_datetime',
        ];
    }
}
