<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderCredential;

interface AuthenticatesProvider extends WritableProviderPort
{
    public function authenticate(ProviderAuthenticationRequest $request): ProviderCredential;
}
