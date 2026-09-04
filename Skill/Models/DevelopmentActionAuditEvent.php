<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidDevelopmentActionException;

final class DevelopmentActionAuditEvent extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_connector_skill_development_action_events';

    protected $guarded = ['id'];

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
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
        $immutable = fn (): never => throw new InvalidDevelopmentActionException(
            'Development action audit events are append-only.',
        );
        self::updating($immutable);
        self::deleting($immutable);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'development_action_event', 'id' => $this->getKey()];
    }
}
