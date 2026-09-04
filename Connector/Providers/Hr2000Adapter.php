<?php

namespace App\Domains\PeopleConnector\Connector\Providers;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\Hr2000DeploymentProfile;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;

final readonly class Hr2000Adapter implements ProviderAdapter, ResolvesProviderPorts
{
    public const ID = 'hr2000.sbg';

    public function __construct(private Hr2000DeploymentProfile $profile) {}

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            id: self::ID,
            name: 'HR2000 (SBG)',
            adapterVersion: '0.1.0',
            contractVersion: '1.0.0',
        );
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([]);
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(
            state: ProviderHealthState::Unknown,
            checkedAt: null,
            message: 'HR2000 discovery is incomplete; no provider connection has been attempted.',
        );
    }

    public function assertActivatable(): void
    {
        $this->profile->assertActivatable();
    }

    public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
    {
        return null;
    }
}
