<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople;

use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
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
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceBootstrapPort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceChangePort;

/**
 * The first-party People provider, seen from the connector side.
 *
 * It declares exactly what the People Provider module publishes today —
 * company, organization-unit and employee reads over the two co-located
 * projection contracts — and nothing else. Positions, merges, writes,
 * reconciliation, service authentication, SSO hand-off and every Skill
 * operation are absent here because People publishes no contract for them;
 * an adapter that declared them would resolve no port and turn a missing
 * contract into a runtime failure instead of an honest capability answer.
 *
 * This adapter is co-located by construction: it calls People in-process
 * through the published contracts. Remote equivalence needs People-owned
 * endpoints and service authentication that do not exist yet, so this is a
 * partial carrier for BelimbingApp/blb-people#27, not its completion.
 */
final readonly class FirstPartyPeopleAdapter implements ProviderAdapter, ResolvesProviderPorts
{
    /**
     * The adapter's identity is the provider identity People already stamps
     * on every reference it publishes. Deriving it here rather than repeating
     * the literal keeps a reference minted by People and a port resolved
     * through this adapter from ever disagreeing about who the provider is.
     */
    public const ID = PeopleExternalReference::PROVIDER_ID;

    public function __construct(
        private WorkforceBootstrapPort $bootstrapPort,
        private WorkforceChangePort $changePort,
    ) {}

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            id: self::ID,
            name: 'Belimbing People (co-located)',
            adapterVersion: '0.1.0',
            contractVersion: WorkforceBootstrapPage::CONTRACT_VERSION,
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
        ]);
    }

    /**
     * A co-located provider shares this process, so there is no transport to
     * probe and nothing that could be reachable-but-degraded: the module is
     * installed and the published contracts resolved, or this adapter would
     * not have been constructed at all.
     */
    public function health(): ProviderHealth
    {
        return new ProviderHealth(
            state: ProviderHealthState::Healthy,
            checkedAt: new \DateTimeImmutable(now()->toISOString()),
            message: 'The People provider module is mounted in this process.',
        );
    }

    public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
    {
        return match ($contract) {
            BootstrapsWorkforce::class => $this->bootstrapPort,
            ReadsWorkforceChanges::class => $this->changePort,
            default => null,
        };
    }
}
