<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

/**
 * One operator action on a connection, as recorded by OperatorAuditLog.
 * Append-only: an audit that can be edited is not one.
 */
final class OperatorAudit extends TenantOwnedModel
{
    public const UPDATED_AT = null;

    protected $table = 'people_connector_connector_operator_audits';

    protected static function booted(): void
    {
        self::updating(fn () => throw new AppendOnlyRecordException('Operator audits are append-only.'));
        self::deleting(fn () => throw new AppendOnlyRecordException('Operator audits are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'related_connection_id' => 'integer',
            'operation' => OperatorAuditOperation::class,
            'actor_id' => 'integer',
            'actor_company_id' => 'integer',
            'before_summary' => 'array',
            'after_summary' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
