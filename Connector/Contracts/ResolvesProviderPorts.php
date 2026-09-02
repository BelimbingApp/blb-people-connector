<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;

/** Internal adapter seam used only after ProviderPortResolver authorization. */
interface ResolvesProviderPorts
{
    /** @param class-string $contract */
    public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object;
}
