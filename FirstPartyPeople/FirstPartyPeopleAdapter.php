<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Contracts\ReadsWorkforcePositions;
use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
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
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceBootstrapPort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceChangePort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceReconciliationPort;

/**
 * The first-party People provider, seen from the connector side.
 *
 * It declares exactly what the People Provider module publishes today —
 * company, organization-unit, position and employee reads over the
 * co-located projection contracts, plus a read-only reconciliation port
 * that diffs those directory contracts against the connection's
 * projections. Merges, writes, service authentication, SSO hand-off and
 * every Skill operation remain undeclared because People publishes no
 * contract for them.
 *
 * Positions have no PeopleCapability case of their own: a position is a
 * node of the organization structure, so People's `ReadsWorkforcePositions`
 * travels under OrganizationDirectory, on the bootstrap page beside the
 * organization units (see WorkforceBootstrapPort). The capability register
 * (docs/providers/capability-register.json) records the same placement.
 * Reconciliation also reads positions through that published contract.
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
        private ReadsWorkforceDirectory $directory,
        private ReadsWorkforcePositions $positions,
        private TenantContext $tenantContext,
        private ProviderConnectionStore $connections,
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
            new CapabilityChannel(CapabilityDelivery::Synchronous, ReconcilesWorkforce::class),
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
            ReconcilesWorkforce::class => new WorkforceReconciliationPort(
                $this->directory,
                $this->positions,
                $this->tenantContext,
                $this->connections,
                $authorization,
            ),
            default => null,
        };
    }
}
