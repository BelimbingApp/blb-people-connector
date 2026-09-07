<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class ConnectorDoctorAlert extends TenantOwnedModel
{
    public const KIND_RED = 'red';

    public const KIND_RECOVERED = 'recovered';

    public $timestamps = false;

    protected $table = 'people_connector_connector_doctor_alerts';

    protected function casts(): array
    {
        return [
            'first_red_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
