<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderEvent;

interface ReceivesProviderEvents extends ProviderPort
{
    public function receive(ProviderEvent $event): void;
}
