<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class PrivilegedSupportAction extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_privileged_support_actions';

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
