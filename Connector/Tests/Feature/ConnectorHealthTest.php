<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectorHealthService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper is prefixed health and lives here, so the file
 * passes or fails alone for its own reasons. The only outside helper is the
 * platform's createTenantWithCompany().
 */

const HEALTH_PROVIDER = 'test.health';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function healthAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException(
                    providerId: 'connector',
                    operation: 'read_health',
                    message: 'The actor lacks the connector health read capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

function healthAdapter(string $contractVersion = '1.4.0'): ProviderAdapter
{
    return new class($contractVersion) implements ProviderAdapter
    {
        public function __construct(private string $contractVersion) {}

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(HEALTH_PROVIDER, 'Health Test Provider', '2.1.0', $this->contractVersion);
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-06T08:00:00+00:00'));
        }
    };
}

/** @return array{tenantId: int, connectionId: int, actor: Actor} */
function healthTenant(string $name, string $contractVersion = '1.4.0'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    config()->set('people-connector.active_provider', HEALTH_PROVIDER);

    if (app(ProviderRegistry::class)->find(HEALTH_PROVIDER) === null) {
        app(ProviderRegistry::class)->register(healthAdapter($contractVersion));
    }

    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), HEALTH_PROVIDER);
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    return [
        'tenantId' => $tenantId,
        'connectionId' => $connectionId,
        'actor' => new Actor(PrincipalType::USER, 8001, (int) $company->id, tenantId: $tenantId),
    ];
}

test('the health read names the active adapter and its declared capabilities', function (): void {
    $f = healthTenant('Health Adapter Tenant');
    healthAuthz(true);

    $health = app(ConnectorHealthService::class)->read($f['actor']);

    expect($health->adapterId)->toBe(HEALTH_PROVIDER)
        ->and($health->adapterName)->toBe('Health Test Provider')
        ->and($health->adapterVersion)->toBe('2.1.0')
        ->and($health->contractVersion)->toBe('1.4.0')
        ->and($health->capabilities)->toContain(PeopleCapability::EmployeeDirectory->value);
});

test('a contract major the connector supports is reported compatible', function (): void {
    $f = healthTenant('Health Compatible Tenant', '1.9.3');
    healthAuthz(true);

    $health = app(ConnectorHealthService::class)->read($f['actor']);

    expect($health->contractCompatible)->toBeTrue()
        ->and($health->supportedContractMajor)->toBe(1);
});

test('an adapter left behind by a platform contract bump is reported incompatible rather than refused', function (): void {
    $f = healthTenant('Health Incompatible Tenant');
    healthAuthz(true);

    // ProviderRegistry refuses an incompatible adapter at registration, so an
    // incompatible one is never active. The drift that does happen is this: the
    // adapter registered cleanly, and the platform later moved to a contract
    // major it does not speak. Incompatibility is then the answer the operator
    // came for, and throwing would hide the fact this read exists to surface.
    config()->set('people-connector.supported_contract_major', 2);
    $health = app(ConnectorHealthService::class)->read($f['actor']);

    expect($health->contractCompatible)->toBeFalse()
        ->and($health->contractVersion)->toBe('1.4.0')
        ->and($health->supportedContractMajor)->toBe(2)
        ->and($health->adapterId)->toBe(HEALTH_PROVIDER);
});

test('a configured provider that never registered is reported as such, not as nothing configured', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Health Unregistered Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    config()->set('people-connector.active_provider', 'test.never-registered');
    healthAuthz(true);
    $actor = new Actor(PrincipalType::USER, 8004, (int) $company->id, tenantId: (int) $tenant->id);

    // "Somebody named an adapter and it is not there" and "nobody has chosen an
    // adapter" are different faults with different fixes. A report that showed
    // both as a blank adapter would send the operator looking in the wrong place.
    $health = app(ConnectorHealthService::class)->read($actor);

    expect($health->adapterId)->toBeNull()
        ->and($health->configuredAdapterId)->toBe('test.never-registered')
        ->and($health->contractCompatible)->toBeFalse();
});

test('the health read reports freshness for each connection in the tenant', function (): void {
    $f = healthTenant('Health Freshness Tenant');
    healthAuthz(true);

    $health = app(ConnectorHealthService::class)->read($f['actor']);
    $connection = collect($health->connections)->firstWhere('connectionId', $f['connectionId']);

    // Nothing has synchronised yet, so the honest answer is stale with a reason,
    // not silence.
    expect($connection)->not->toBeNull()
        ->and($connection->stale)->toBeTrue()
        ->and($connection->staleReason)->toBe('never_synchronized');
});

test('a caller without the operator capability is refused', function (): void {
    $f = healthTenant('Health Denied Tenant');
    healthAuthz(false);

    expect(fn () => app(ConnectorHealthService::class)->read($f['actor']))
        ->toThrow(ProviderAuthorizationException::class);
});

test('an actor from outside the current tenant is refused', function (): void {
    $f = healthTenant('Health Foreign Actor Tenant');
    healthAuthz(true);
    $outsider = new Actor(PrincipalType::USER, 8002, null, tenantId: $f['tenantId'] + 1);

    expect(fn () => app(ConnectorHealthService::class)->read($outsider))
        ->toThrow(ProviderAuthorizationException::class);
});

test('the health read never reports a connection from another tenant', function (): void {
    $mine = healthTenant('Health Mine Tenant');
    $theirs = healthTenant('Health Theirs Tenant');
    app(TenantContext::class)->set($mine['tenantId']);
    healthAuthz(true);

    $health = app(ConnectorHealthService::class)->read($mine['actor']);

    expect(collect($health->connections)->pluck('connectionId')->all())->toBe([$mine['connectionId']])
        ->and(collect($health->connections)->pluck('connectionId')->all())->not->toContain($theirs['connectionId']);
});

test('no adapter registered is reported as no active adapter rather than an error', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Health No Adapter Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    config()->set('people-connector.active_provider', null);
    healthAuthz(true);
    $actor = new Actor(PrincipalType::USER, 8003, (int) $company->id, tenantId: (int) $tenant->id);

    $health = app(ConnectorHealthService::class)->read($actor);

    expect($health->adapterId)->toBeNull()
        ->and($health->configuredAdapterId)->toBeNull()
        ->and($health->contractCompatible)->toBeFalse()
        ->and($health->capabilities)->toBe([]);
});
