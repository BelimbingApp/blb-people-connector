<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class ReconciliationIssue extends TenantOwnedModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'people_connector_connector_reconciliation_issues';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
