<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderCredential;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ProviderUiHandoff;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthenticationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\PrivilegedSupportAction;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Services\PrivilegedSupportService;
use App\Domains\PeopleConnector\Connector\Services\ProviderAccessAuthorizer;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderCredentialStore;
use Illuminate\Support\Collection;

function peopleConnectorTestActor(int $tenantId = 1, int $companyId = 10, int $id = 20): Actor
{
    return new Actor(PrincipalType::USER, $id, $companyId, tenantId: $tenantId);
}

function peopleConnectorTestActivateConnection(int $tenantId, int $companyId, string $providerId): void
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company($companyId), $providerId);
    $store->activate((int) $connection->id);
}

test('provider access authorizes the actor before resolving an adapter port', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Access Tenant']);
    peopleConnectorTestActivateConnection((int) $tenant->id, (int) $company->id, 'test.provider');
    $authorization = new class implements AuthorizationService
    {
        public bool $called = false;

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            $this->called = true;

            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->called = true;
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    };
    app()->instance(AuthorizationService::class, $authorization);
    $port = Mockery::mock(BootstrapsWorkforce::class);
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor(
        'test.provider', 'Test Provider', '1.0.0', '1.0.0',
    ));
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
        ]),
    ]));
    $provider->shouldReceive('resolvePort')->once()->with(BootstrapsWorkforce::class)->andReturn($port);

    $resolved = app(ProviderAccessAuthorizer::class)->read(
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id),
        $provider,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::company((int) $company->id),
    );

    expect($resolved)->toBe($port)->and($authorization->called)->toBeTrue();
});

test('denied connector authorization never asks an adapter for capabilities or a port', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Denied Access Tenant']);
    peopleConnectorTestActivateConnection((int) $tenant->id, (int) $company->id, 'test.provider');
    $authorization = new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            throw new AuthorizationDeniedException(
                AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY),
            );
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect();
        }
    };
    app()->instance(AuthorizationService::class, $authorization);
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor(
        'test.provider', 'Test Provider', '1.0.0', '1.0.0',
    ));
    $provider->shouldNotReceive('capabilities');
    $provider->shouldNotReceive('resolvePort');

    expect(fn () => app(ProviderAccessAuthorizer::class)->read(
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id),
        $provider,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::company((int) $company->id),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('provider access rejects a cross-tenant or cross-company actor before authorization', function (): void {
    app(TenantContext::class)->set(1);
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldNotReceive('authorize');
    app()->instance(AuthorizationService::class, $authorization);

    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor(
        'test.provider', 'Test Provider', '1.0.0', '1.0.0',
    ));

    expect(fn () => app(ProviderAccessAuthorizer::class)->read(
        peopleConnectorTestActor(tenantId: 2),
        $provider,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::company(10),
    ))->toThrow(ProviderAuthorizationException::class);
});

test('provider access rejects an installed adapter that is not active for the scope', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Inactive Adapter Tenant']);
    peopleConnectorTestActivateConnection((int) $tenant->id, (int) $company->id, 'other.provider');
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldNotReceive('authorize');
    app()->instance(AuthorizationService::class, $authorization);

    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor(
        'test.provider', 'Test Provider', '1.0.0', '1.0.0',
    ));
    $provider->shouldNotReceive('capabilities');

    expect(fn () => app(ProviderAccessAuthorizer::class)->read(
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id),
        $provider,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::company((int) $company->id),
    ))->toThrow(ProviderAuthorizationException::class);
});

test('credential claims contain rotation metadata but never a secret', function (): void {
    $issuedAt = new DateTimeImmutable('2026-09-02T00:00:00+00:00');
    $credential = new ProviderCredential(
        credentialId: 'pcred_123',
        keyId: 'key-2026-09',
        providerId: 'test.provider',
        audience: 'blb-people-connector',
        scopes: ['employee_directory:read'],
        issuedAt: $issuedAt,
        expiresAt: $issuedAt->modify('+5 minutes'),
    );

    expect($credential->allows('blb-people-connector', 'employee_directory:read', $issuedAt->modify('+1 minute')))->toBeTrue()
        ->and($credential->allows('other-audience', 'employee_directory:read', $issuedAt->modify('+1 minute')))->toBeFalse()
        ->and($credential->publicClaims())->not->toHaveKey('secret')
        ->and($credential->publicClaims())->not->toHaveKey('secret_reference');
});

test('provider UI handoffs reject credential-like URL parameters', function (): void {
    expect(fn () => new ProviderUiHandoff(
        'https://provider.example.test/sso?access_token=reusable',
        new DateTimeImmutable('+5 minutes'),
        'opaque-one-time-handle',
    ))->toThrow(InvalidArgumentException::class);
});

test('credential resolution rejects a credential after its connection is deactivated', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Credential Revocation Tenant']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    peopleConnectorTestActivateConnection($tenantId, $companyId, 'test.provider');
    $connections = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company($companyId);
    $connection = $connections->active($scope);
    $issuedAt = new DateTimeImmutable('2026-09-02T00:00:00+00:00');
    $request = new ProviderAuthenticationRequest(
        $tenantId,
        (int) $connection->id,
        'blb-people-connector',
        ['employee_directory:read'],
    );
    $store = app(ProviderCredentialStore::class);
    $store->issue($request, $connection, 'key-2026-09', 'base-integration:test-provider', $issuedAt, $issuedAt->modify('+5 minutes'));

    peopleConnectorTestActivateConnection($tenantId, $companyId, 'replacement.provider');

    expect(fn () => $store->requireUsable($request, $issuedAt->modify('+1 minute')))
        ->toThrow(ProviderAuthenticationException::class);
});

test('invalid credential lifetimes never persist a credential record', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Credential Lifetime Tenant']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    peopleConnectorTestActivateConnection($tenantId, $companyId, 'test.provider');
    $connection = app(ProviderConnectionStore::class)->active(ProviderScope::company($companyId));
    $issuedAt = new DateTimeImmutable('2026-09-02T00:00:00+00:00');
    $request = new ProviderAuthenticationRequest(
        $tenantId,
        (int) $connection->id,
        'blb-people-connector',
        ['employee_directory:read'],
    );

    expect(fn () => app(ProviderCredentialStore::class)->issue(
        $request,
        $connection,
        'key-2026-09',
        'base-integration:test-provider',
        $issuedAt,
        $issuedAt->modify('+6 minutes'),
    ))->toThrow(InvalidArgumentException::class)
        ->and(ProviderCredentialRecord::query()->count())->toBe(0);
});

test('break-glass access requires a separate approver and records its actions', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Support Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldReceive('authorize')->times(3)->andReturnNull();
    app()->instance(AuthorizationService::class, $authorization);

    $issuedAt = new DateTimeImmutable;
    $grant = app(PrivilegedSupportService::class)->issue(
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 20),
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 21),
        ProviderScope::company((int) $company->id),
        ['employee_directory:read'],
        'Investigate an approved provider outage',
        $issuedAt,
        $issuedAt->modify('+15 minutes'),
    );

    $action = app(PrivilegedSupportService::class)->recordAction(
        $grant,
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 20),
        'employee_directory:read',
        'provider_health_read',
        'completed',
    );

    expect($grant->isActive($issuedAt->modify('+1 minute')))->toBeTrue()
        ->and($action->grant_id)->toBe($grant->id)
        ->and($action->context['capability'])->toBe('employee_directory:read')
        ->and(PrivilegedSupportAction::query()
            ->where('grant_id', $grant->id)->count())->toBe(2);
});

test('expired break-glass access cannot record a provider action', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Expired Support Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldReceive('authorize')->times(3)->andReturnNull();
    app()->instance(AuthorizationService::class, $authorization);

    $issuedAt = new DateTimeImmutable('2026-09-01T00:00:00+00:00');
    $service = app(PrivilegedSupportService::class);
    $grant = $service->issue(
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 30),
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 31),
        ProviderScope::company((int) $company->id),
        ['employee_directory:read'],
        'Expired grant test',
        $issuedAt,
        $issuedAt->modify('+15 minutes'),
    );

    expect(fn () => $service->recordAction(
        $grant,
        peopleConnectorTestActor((int) $tenant->id, (int) $company->id, 30),
        'employee_directory:read',
        'provider_health_read',
        'completed',
        occurredAt: new DateTimeImmutable('2026-09-02T00:00:00+00:00'),
    ))->toThrow(ProviderAuthorizationException::class);
});
