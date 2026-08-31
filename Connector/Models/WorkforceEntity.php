<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class WorkforceEntity extends TenantOwnedModel
{
    public const STATE_ACTIVE = 'active';

    public const STATE_INACTIVE = 'inactive';

    public const STATE_MERGED = 'merged';

    protected $table = 'people_connector_connector_workforce_entities';

    protected function casts(): array
    {
        return [
            'merged_into_entity_id' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'merged_at' => 'immutable_datetime',
        ];
    }
}
