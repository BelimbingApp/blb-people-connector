<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class SyncCheckpoint extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_sync_checkpoints';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'as_of_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
