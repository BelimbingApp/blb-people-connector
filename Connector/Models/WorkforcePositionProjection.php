<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

final class WorkforcePositionProjection extends TenantOwnedModel
{
    use CompanyOwned;

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
