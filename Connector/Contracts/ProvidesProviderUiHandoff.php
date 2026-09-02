<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderUiHandoff;

interface ProvidesProviderUiHandoff
{
    /** @param array<string, mixed> $context */
    public function createHandoff(array $context = []): ProviderUiHandoff;
}
