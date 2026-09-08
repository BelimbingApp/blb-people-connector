<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class ConnectorDoctorSnapshot extends TenantOwnedModel
{
    public $timestamps = false;

    protected $table = 'people_connector_connector_doctor_snapshots';

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'measured_at' => 'immutable_datetime',
        ];
    }
}
