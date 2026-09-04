<?php

namespace App\Domains\PeopleConnector\NativePeople\Providers;

use App\Domains\People\Provider\Data\ExternalReference as NativeReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage as NativeBootstrapPage;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;

final readonly class NativePeopleAdapter implements ProviderAdapter, ResolvesProviderPorts
{
    public const ID = NativeReference::PROVIDER_ID;

    public function __construct(private NativePeopleWorkforceSource $source) {}

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            id: self::ID,
            name: 'Belimbing People',
            adapterVersion: '0.1.0',
            contractVersion: NativeBootstrapPage::CONTRACT_VERSION,
            placement: 'colocated',
        );
    }

    public function capabilities(): CapabilitySet
    {
        $channels = [
            new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
            new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
        ];

        return new CapabilitySet([
            new CapabilityDeclaration(PeopleCapability::CompanyDirectory, $channels),
            new CapabilityDeclaration(PeopleCapability::OrganizationDirectory, $channels),
            new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, $channels),
            new CapabilityDeclaration(PeopleCapability::ManagerHierarchy, $channels),
        ]);
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(
            state: ProviderHealthState::Unknown,
            checkedAt: null,
            message: 'The co-located People projection is installed; no provider health contract is published.',
        );
    }

    public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
    {
        return is_a($this->source, $contract) ? $this->source : null;
    }
}
