<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum ProviderHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
