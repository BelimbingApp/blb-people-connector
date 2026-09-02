<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

final class PrivilegedSupportAction extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_privileged_support_actions';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new AppendOnlyRecordException('Privileged support actions are append-only.');
        });
        self::deleting(function (): void {
            throw new AppendOnlyRecordException('Privileged support actions cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'grant_id' => 'integer',
            'actor_user_id' => 'integer',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
