<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum CapabilityDelivery: string
{
    case Synchronous = 'synchronous';
    case Asynchronous = 'asynchronous';
    case FileExchange = 'file_exchange';
    case ProviderUi = 'provider_ui';
}
