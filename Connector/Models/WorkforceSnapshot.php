<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

final class WorkforceSnapshot extends TenantOwnedModel
{
    public const UPDATED_AT = null;

    protected $table = 'people_connector_connector_workforce_snapshots';

    protected static function booted(): void
    {
        self::updating(fn () => throw new AppendOnlyRecordException('Workforce snapshots are append-only.'));
        self::deleting(fn () => throw new AppendOnlyRecordException('Workforce snapshots are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'payload' => 'array',
            'provenance' => 'array',
        ];
    }
}
