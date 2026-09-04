<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus;

final class TrainingEvent extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_training_events';

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('target_department_entity_id', WorkforceResourceType::OrganizationUnit),
            new WorkforceReference('organizer_employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('internal_trainer_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected function casts(): array
    {
        return [
            'delivery_mode_snapshot' => DeliveryMode::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'status' => TrainingEventStatus::class,
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_event', 'id' => $this->getKey()];
    }
}
