<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

enum Hr2000Transport: string
{
    case Unverified = 'unverified';
    case FileExchange = 'file_exchange';
    case RemoteHttp = 'remote_http';
    case DirectDatabase = 'direct_database';

    public static function fromConfiguration(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidProviderConfigurationException(
                'Unsupported HR2000 transport. Screen scraping and undocumented interfaces are not permitted.',
            );
    }
}
