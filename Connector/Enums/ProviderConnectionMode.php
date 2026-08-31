<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum ProviderConnectionMode: string
{
    case InProcess = 'in_process';
    case RemoteHttp = 'remote_http';
    case FileExchange = 'file_exchange';
    case ProviderUi = 'provider_ui';
}
