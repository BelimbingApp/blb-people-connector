<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingEventException;

final class TrainingEventAuditEvent extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_connector_training_event_audit_events';

    public function workforceReferences(): array
    {
        return [new WorkforceReference('actor_employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new InvalidTrainingEventException(
            'Training event audit records are append-only.',
        );

        self::updating($immutable);
        self::deleting($immutable);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_event_audit_event', 'id' => $this->getKey()];
    }
}
