<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

final class RetentionPurgeAudit extends TenantOwnedModel
{
    public const UPDATED_AT = null;

    protected $table = 'people_connector_connector_retention_purge_audits';

    protected static function booted(): void
    {
        self::updating(fn () => throw new AppendOnlyRecordException('Retention purge audits are append-only.'));
        self::deleting(fn () => throw new AppendOnlyRecordException('Retention purge audits are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'expected_count' => 'integer',
            'deleted_count' => 'integer',
            'report_reviewed_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
        ];
    }
}
