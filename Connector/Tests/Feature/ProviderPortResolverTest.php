<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderPortResolver;
use Illuminate\Support\Collection;

interface TestEmployeeReader extends ReadableProviderPort {}

interface TestEmployeeWriter extends WritableProviderPort {}

function providerDescriptor(): ProviderDescriptor
{
    return new ProviderDescriptor('test.provider', 'Test Provider', '1.0.0', '1.0.0');
}

function resolverTestActor(int $tenantId, int $companyId, int $id = 20): Actor
{
    return new Actor(PrincipalType::USER, $id, $companyId, tenantId: $tenantId);
}

function resolverTestActivateConnection(int $tenantId, int $companyId, string $providerId): void
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company($companyId), $providerId);
    $store->activate((int) $connection->id);
}

/**
 * A tenant with a company, an active `test.provider` connection for that
 * company, and a recording authorization service that allows everything.
 *
 * @return array{Actor, ProviderScope, object}
 */
function resolverTestAuthorizedAccess(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    resolverTestActivateConnection((int) $tenant->id, (int) $company->id, 'test.provider');

    $authorization = new class implements AuthorizationService
    {
        /** @var list<string> */
        public array $authorized = [];

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->authorized[] = $capability;
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    };
    app()->instance(AuthorizationService::class, $authorization);

    return [
        resolverTestActor((int) $tenant->id, (int) $company->id),
        ProviderScope::company((int) $company->id),
        $authorization,
    ];
}

test('a port resolver cannot be constructed without its authorization dependencies', function (): void {
    expect(fn () => new ProviderPortResolver)->toThrow(ArgumentCountError::class);
});

test('an adapter without the internal resolver seam cannot expose a port', function (): void {
    [$actor, $scope] = resolverTestAuthorizedAccess('Missing Adapter Resolver Tenant');
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeReader::class),
        ]),
    ]));

    expect(fn () => app(ProviderPortResolver::class)->read(
        $actor,
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        $scope,
    ))->toThrow(ProviderCompatibilityException::class, 'no authorized port resolver');
});

test('a port resolver built around its constructor cannot reach an adapter', function (): void {
    app(TenantContext::class)->set(1);
    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldNotReceive('descriptor');
    $provider->shouldNotReceive('capabilities');
    $provider->shouldNotReceive('resolvePort');

    $resolver = (new ReflectionClass(ProviderPortResolver::class))->newInstanceWithoutConstructor();

    expect(fn () => $resolver->read(
        resolverTestActor(tenantId: 1, companyId: 10),
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        ProviderScope::company(10),
    ))->toThrow(Error::class, 'must not be accessed before initialization');
});

test('unsupported writes fail before the adapter is asked to resolve a port', function (): void {
    [$actor, $scope] = resolverTestAuthorizedAccess('Unsupported Write Tenant');
    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([]));
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldNotReceive('resolvePort');

    expect(fn () => app(ProviderPortResolver::class)->write(
        $actor,
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeWriter::class,
        $scope,
    ))->toThrow(UnsupportedProviderOperation::class, 'does not support write access');
});

test('a declared port that cannot be resolved is a compatibility failure', function (): void {
    [$actor, $scope] = resolverTestAuthorizedAccess('Compatibility Tenant');
    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeWriter::class),
        ]),
    ]));
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeWriter::class, Mockery::type(ProviderPortAuthorization::class))->andReturnNull();

    expect(fn () => app(ProviderPortResolver::class)->write(
        $actor,
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeWriter::class,
        $scope,
    ))->toThrow(ProviderCompatibilityException::class, 'declares');
});

test('declared readable and writable ports resolve with their exact type after authorization', function (): void {
    [$actor, $scope, $authorization] = resolverTestAuthorizedAccess('Resolution Tenant');
    $reader = new class implements TestEmployeeReader {};
    $writer = new class implements TestEmployeeWriter {};
    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('capabilities')->twice()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeReader::class),
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeWriter::class),
        ]),
    ]));
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeReader::class, Mockery::type(ProviderPortAuthorization::class))->andReturn($reader);
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeWriter::class, Mockery::type(ProviderPortAuthorization::class))->andReturn($writer);

    $resolver = app(ProviderPortResolver::class);

    expect($resolver->read($actor, $provider, PeopleCapability::EmployeeDirectory, TestEmployeeReader::class, $scope))->toBe($reader)
        ->and($resolver->write($actor, $provider, PeopleCapability::EmployeeDirectory, TestEmployeeWriter::class, $scope))->toBe($writer)
        ->and($authorization->authorized)->toBe([
            'people-connector.provider.read.employee_directory',
            'people-connector.provider.write.employee_directory',
        ]);
});

test('denied connector authorization never asks an adapter for capabilities or a port', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Denied Access Tenant']);
    resolverTestActivateConnection((int) $tenant->id, (int) $company->id, 'test.provider');

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

    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldNotReceive('capabilities');
    $provider->shouldNotReceive('resolvePort');

    expect(fn () => app(ProviderPortResolver::class)->read(
        resolverTestActor((int) $tenant->id, (int) $company->id),
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        ProviderScope::company((int) $company->id),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('a permission granted for one capability does not authorize another', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Capability Isolation Tenant']);
    resolverTestActivateConnection((int) $tenant->id, (int) $company->id, 'test.provider');

    // Grants only the Employee Directory read permission. Before the
    // capability was folded into the permission name, this same actor could
    // read Payroll through the identical 'people-connector.provider.read'
    // check — see docs/contracts/hr-data-boundary.md rule 7.3.
    $authorization = new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $capability === 'people-connector.provider.read.employee_directory'
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if ($capability !== 'people-connector.provider.read.employee_directory') {
                throw new AuthorizationDeniedException(
                    AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY),
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $capability === 'people-connector.provider.read.employee_directory' ? collect($resources) : collect();
        }
    };
    app()->instance(AuthorizationService::class, $authorization);

    $reader = new class implements TestEmployeeReader {};
    $employeeDirectoryProvider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $employeeDirectoryProvider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $employeeDirectoryProvider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeReader::class),
        ]),
    ]));
    $employeeDirectoryProvider->shouldReceive('resolvePort')->once()->with(TestEmployeeReader::class, Mockery::type(ProviderPortAuthorization::class))->andReturn($reader);

    expect(app(ProviderPortResolver::class)->read(
        resolverTestActor((int) $tenant->id, (int) $company->id),
        $employeeDirectoryProvider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        ProviderScope::company((int) $company->id),
    ))->toBe($reader);

    $payrollProvider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $payrollProvider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $payrollProvider->shouldNotReceive('capabilities');
    $payrollProvider->shouldNotReceive('resolvePort');

    expect(fn () => app(ProviderPortResolver::class)->read(
        resolverTestActor((int) $tenant->id, (int) $company->id),
        $payrollProvider,
        PeopleCapability::Payroll,
        TestEmployeeReader::class,
        ProviderScope::company((int) $company->id),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('a cross-tenant or cross-company actor is rejected before the authorization service is consulted', function (): void {
    app(TenantContext::class)->set(1);
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldNotReceive('authorize');
    app()->instance(AuthorizationService::class, $authorization);

    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldNotReceive('capabilities');
    $resolver = app(ProviderPortResolver::class);

    expect(fn () => $resolver->read(
        resolverTestActor(tenantId: 2, companyId: 10),
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        ProviderScope::company(10),
    ))->toThrow(ProviderAuthorizationException::class)
        ->and(fn () => $resolver->write(
            resolverTestActor(tenantId: 1, companyId: 11),
            $provider,
            PeopleCapability::EmployeeDirectory,
            TestEmployeeWriter::class,
            ProviderScope::company(10),
        ))->toThrow(ProviderAuthorizationException::class);
});

test('an installed adapter that is not active for the scope is rejected before the authorization service is consulted', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Inactive Adapter Tenant']);
    resolverTestActivateConnection((int) $tenant->id, (int) $company->id, 'other.provider');
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldNotReceive('authorize');
    app()->instance(AuthorizationService::class, $authorization);

    $provider = Mockery::mock(ProviderAdapter::class, ResolvesProviderPorts::class);
    $provider->shouldReceive('descriptor')->andReturn(providerDescriptor());
    $provider->shouldNotReceive('capabilities');

    expect(fn () => app(ProviderPortResolver::class)->read(
        resolverTestActor((int) $tenant->id, (int) $company->id),
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeReader::class,
        ProviderScope::company((int) $company->id),
    ))->toThrow(ProviderAuthorizationException::class);
});

test('provider validation failures retain provider operation and structured context', function (): void {
    $exception = new ProviderValidationException(
        providerId: 'test.provider',
        operation: 'employee.update',
        message: 'Employee number is required.',
        context: ['field' => 'employee_number', 'code' => 'required'],
    );

    expect($exception->providerId)->toBe('test.provider')
        ->and($exception->operation)->toBe('employee.update')
        ->and($exception->context)->toBe(['field' => 'employee_number', 'code' => 'required']);
});
