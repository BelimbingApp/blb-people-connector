<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

final class WorkforceEmployeeProjection extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_connector_workforce_employees';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('user_entity_id', WorkforceResourceType::User),
            new WorkforceReference('organization_entity_id', WorkforceResourceType::OrganizationUnit),
            new WorkforceReference('position_entity_id', WorkforceResourceType::Position),
            new WorkforceReference('manager_entity_id', WorkforceResourceType::Employee, hierarchy: true),
            new WorkforceReference('department_head_entity_id', WorkforceResourceType::Employee, hierarchy: true),
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
