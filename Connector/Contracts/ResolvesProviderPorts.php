<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

/** Internal adapter seam used only after ProviderPortResolver authorization. */
interface ResolvesProviderPorts
{
    /** @param class-string $contract */
    public function resolvePort(string $contract): ?object;
}
