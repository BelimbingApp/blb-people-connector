<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

final class SyncCheckpointEvent extends TenantOwnedModel
{
    public const UPDATED_AT = null;

    protected $table = 'people_connector_connector_sync_checkpoint_events';

    protected static function booted(): void
    {
        self::updating(fn () => throw new AppendOnlyRecordException('Sync checkpoint events are append-only.'));
        self::deleting(fn () => throw new AppendOnlyRecordException('Sync checkpoint events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'as_of_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
