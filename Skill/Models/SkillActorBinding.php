<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;

final class SkillActorBinding extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_actor_bindings';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('user_entity_id', WorkforceResourceType::User),
        ];
    }

    protected function casts(): array
    {
        return [
            'platform_user_id' => 'integer',
            'employee_entity_id' => 'integer',
            'user_entity_id' => 'integer',
            'confirmed_by_user_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'revoked_by_user_id' => 'integer',
        ];
    }

    /** @return array{name: string, id: int} */
    public function getAuditSubject(): array
    {
        return ['name' => 'skill_actor_binding', 'id' => (int) $this->getKey()];
    }
}
