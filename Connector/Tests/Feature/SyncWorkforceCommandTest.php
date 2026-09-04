<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipalGrants;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const SYNC_CMD_PROVIDER = 'test.sync.cmd';

function syncCmdCompanyRef(string $id): ExternalReference
{
    return new ExternalReference(SYNC_CMD_PROVIDER, WorkforceResourceType::Company, $id);
}

test('activating a connection grants the scheduler directory-read capabilities', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sync Grants Co']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), SYNC_CMD_PROVIDER);

    expect(PrincipalCapability::query()
        ->where('principal_type', PrincipalType::SCHEDULER->value)
        ->where('principal_id', $connection->id)
        ->count())->toBe(0);

    $store->activate((int) $connection->id);

    $keys = PrincipalCapability::query()
        ->where('principal_type', PrincipalType::SCHEDULER->value)
        ->where('principal_id', $connection->id)
        ->pluck('capability_key')
        ->sort()
        ->values()
        ->all();

    $expected = collect(SchedulerPrincipalGrants::directoryReadCapabilities())
        ->map(fn (PeopleCapability $c) => ProviderPortAuthorization::permissionFor($c, 'read'))
        ->sort()
        ->values()
        ->all();

    expect($keys)->toBe($expected)
        ->and(PrincipalCapability::query()
            ->where('principal_id', $connection->id)
            ->where('company_id', (int) $company->id)
            ->count())->toBe(count($expected));
});

test('activating a sibling connection revokes the previous scheduler grants', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sync Sibling Co']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $first = $store->configure(ProviderScope::company((int) $company->id), 'test.sync.first');
    $second = $store->configure(ProviderScope::company((int) $company->id), 'test.sync.second');
    $store->activate((int) $first->id);
    $store->activate((int) $second->id);

    expect(PrincipalCapability::query()
        ->where('principal_type', PrincipalType::SCHEDULER->value)
        ->where('principal_id', $first->id)
        ->count())->toBe(0)
        ->and(PrincipalCapability::query()
            ->where('principal_type', PrincipalType::SCHEDULER->value)
            ->where('principal_id', $second->id)
            ->count())->toBe(count(SchedulerPrincipalGrants::directoryReadCapabilities()));
});

test('the scheduler principal omits company for a tenant-scoped connection', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Sync Tenant Scope']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::tenant(), SYNC_CMD_PROVIDER);
    $store->activate((int) $connection->id);

    $actor = app(SchedulerPrincipal::class)->forConnection($connection->refresh());

    expect($actor->type)->toBe(PrincipalType::SCHEDULER)
        ->and($actor->id)->toBe((int) $connection->id)
        ->and($actor->companyId)->toBeNull()
        ->and($actor->tenantId)->toBe((int) $tenant->id)
        ->and($actor->validate())->toBeNull()
        ->and(PrincipalCapability::query()
            ->where('principal_id', $connection->id)
            ->whereNull('company_id')
            ->count())->toBe(count(SchedulerPrincipalGrants::directoryReadCapabilities()));
});

test('people-connector:sync bootstraps an active connection through the scheduler principal', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sync Command Co']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), SYNC_CMD_PROVIDER);
    $store->activate((int) $connection->id);

    // Capability keys are connector-owned; KnownCapabilityPolicy would deny them
    // unless registered in platform authz.php. The runner's own suite stubs the
    // same way — this test is about minting the SCHEDULER actor and driving the
    // command, not about publishing every directory permission into Base Authz.
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });

    $at = new DateTimeImmutable('2026-09-04T00:00:00+00:00');
    $page = new WorkforcePage(
        employees: [],
        asOf: $at,
        resumeCursor: 'boot-done',
        complete: true,
        companies: [new WorkforceCompany(syncCmdCompanyRef('C1'), 'Cmd Co', true, $at)],
    );

    $adapter = new class($page) implements ProviderAdapter, ResolvesProviderPorts
    {
        public function __construct(private WorkforcePage $page) {}

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(SYNC_CMD_PROVIDER, 'Sync Cmd', '0.1.0', '1.0.0');
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                    new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return new class($this->page) implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                public function __construct(private WorkforcePage $page) {}

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    return $this->page;
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    return new WorkforceChangePage([], $this->page->asOf, resumeCursor: $this->page->resumeCursor, complete: true);
                }
            };
        }
    };

    app(ProviderRegistry::class)->register($adapter);
    app(TenantContext::class)->clear();

    $exit = Artisan::call('people-connector:sync', [
        'connection' => (int) $connection->id,
        '--bootstrap' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('pass=bootstrap');

    app(TenantContext::class)->set((int) $tenant->id);
    expect(app(SyncCheckpointStore::class)
        ->current((int) $connection->id, WorkforceFreshnessPolicy::stream()))->not->toBeNull();
});
