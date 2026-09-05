<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Training\Enums\TrainingRequestStatus;

final class TrainingRequest extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_training_requests';

    protected function casts(): array
    {
        return ['status' => TrainingRequestStatus::class, 'proposed_budget_minor' => 'integer'];
    }

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('requester_employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('department_entity_id', WorkforceResourceType::OrganizationUnit),
        ];
    }
}
