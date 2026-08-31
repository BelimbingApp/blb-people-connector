<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class WorkforcePositionProjection extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_workforce_positions';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
