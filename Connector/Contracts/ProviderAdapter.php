<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;

interface ProviderAdapter
{
    public function descriptor(): ProviderDescriptor;

    public function capabilities(): CapabilitySet;

    public function health(): ProviderHealth;
}
