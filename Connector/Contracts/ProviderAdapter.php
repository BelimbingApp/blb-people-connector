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

    /**
     * Resolve one provider-neutral port declared by this adapter.
     *
     * @template TPort of object
     *
     * @param  class-string<TPort>  $contract
     * @return TPort|null
     */
    public function resolvePort(string $contract): ?object;
}
