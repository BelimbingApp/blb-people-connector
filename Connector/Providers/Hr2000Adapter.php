<?php

namespace App\Domains\PeopleConnector\Connector\Providers;

use App\Domains\PeopleConnector\Connector\Contracts\ImportsWorkforceFiles;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\Hr2000DeploymentProfile;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;

final readonly class Hr2000Adapter implements ProviderAdapter, ResolvesProviderPorts
{
    public const ID = 'hr2000.sbg';

    public function __construct(
        private Hr2000DeploymentProfile $profile,
        private Hr2000EmployeeCsvParser $filePort = new Hr2000EmployeeCsvParser,
    ) {}

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
        return new CapabilitySet([
            new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                new CapabilityChannel(
                    CapabilityDelivery::FileExchange,
                    ImportsWorkforceFiles::class,
                    notes: 'Verified candidate-1 employee CSV inspection; projection application remains disabled.',
                ),
            ]),
        ]);
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
        return $contract === ImportsWorkforceFiles::class ? $this->filePort : null;
    }
}
