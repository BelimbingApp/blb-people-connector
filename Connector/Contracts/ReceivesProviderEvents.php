<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderEvent;

interface ReceivesProviderEvents extends ReadableProviderPort
{
    public function receive(ProviderEvent $event): void;
}
