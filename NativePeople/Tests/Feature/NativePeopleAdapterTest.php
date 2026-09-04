<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Core\Company\Models\Company;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap as ReadsNativeWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsNativeWorkforceChanges;
use App\Domains\People\Provider\Data\ExternalReference as NativeReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage as NativeBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest as NativeBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceChangePage as NativeChangePage;
use App\Domains\People\Provider\Data\WorkforceChangeRequest as NativeChangeRequest;
use App\Domains\People\Provider\Data\WorkforceCompany as NativeCompany;
use App\Domains\People\Provider\Data\WorkforceDeactivation as NativeDeactivation;
use App\Domains\People\Provider\Data\WorkforceEmployee as NativeEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit as NativeOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceUpsert as NativeUpsert;
use App\Domains\People\Provider\Enums\WorkforceResourceType as NativeResourceType;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
use App\Domains\People\Provider\Services\NativeWorkforceBootstrapReader;
use App\Domains\People\Provider\Services\NativeWorkforceChangeReader;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderPortResolver;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;
use App\Domains\PeopleConnector\NativePeople\Providers\NativePeopleAdapter;
use App\Domains\PeopleConnector\NativePeople\ServiceProvider as NativePeopleServiceProvider;
use Illuminate\Support\Collection;

test('the first-party adapter registers and declares only published co-located reads', function (): void {
    $adapter = app(NativePeopleAdapter::class);
    $capabilities = $adapter->capabilities();

    expect(app(ProviderRegistry::class)->find(NativePeopleAdapter::ID))->toBe($adapter)
        ->and($adapter->descriptor()->id)->toBe('blb-people')
        ->and($adapter->descriptor()->placement)->toBe('colocated')
        ->and($capabilities->direction(PeopleCapability::CompanyDirectory))->toBe(CapabilityDirection::None)
        ->and($capabilities->direction(PeopleCapability::OrganizationDirectory))->toBe(CapabilityDirection::None)
        ->and($capabilities->direction(PeopleCapability::EmployeeDirectory))->toBe(CapabilityDirection::Read)
        ->and($capabilities->direction(PeopleCapability::ManagerHierarchy))->toBe(CapabilityDirection::None)
        ->and($capabilities->direction(PeopleCapability::UserDirectory))->toBe(CapabilityDirection::None)
        ->and($capabilities->direction(PeopleCapability::SingleSignOn))->toBe(CapabilityDirection::None)
        ->and($capabilities->direction(PeopleCapability::Training))->toBe(CapabilityDirection::None)
        ->and($adapter->resolvePort(
            ReconcilesWorkforce::class,
            ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
        ))->toBeNull();
});

test('company-scoped authorization cannot open the tenant-wide native reader', function (): void {
    [$tenant, $companyA] = createTenantWithCompany(['name' => 'Native Scope Tenant']);
    Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Native Scope Company B']);
    $tenantId = (int) $tenant->id;
    $companyAId = (int) $companyA->id;
    nativePeopleActivateConnection($tenantId, ProviderScope::company($companyAId));
    nativePeopleAllowAuthorization();

    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldNotReceive('read');
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    expect(Company::query()->forTenant($tenantId)->count())->toBe(2);

    expect(fn () => app(ProviderPortResolver::class)->read(
        new Actor(PrincipalType::USER, 41, $companyAId, tenantId: $tenantId),
        app(NativePeopleAdapter::class),
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::company($companyAId),
    ))->toThrow(ProviderCompatibilityException::class, 'cannot resolve a compatible implementation');
});

test('a company-directory grant cannot resolve the employee-bearing aggregate port', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Native Capability Tenant']);
    $tenantId = (int) $tenant->id;
    nativePeopleActivateConnection($tenantId, ProviderScope::tenant());
    nativePeopleAllowAuthorization();

    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldNotReceive('read');
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    expect(fn () => app(ProviderPortResolver::class)->read(
        new Actor(PrincipalType::SCHEDULER, 42, null, tenantId: $tenantId),
        app(NativePeopleAdapter::class),
        PeopleCapability::CompanyDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::tenant(),
    ))->toThrow(UnsupportedProviderOperation::class, 'does not support read access');
});

test('a tenant-scoped employee-directory grant can resolve the aggregate port', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Native Tenant Grant']);
    $tenantId = (int) $tenant->id;
    nativePeopleActivateConnection($tenantId, ProviderScope::tenant());
    nativePeopleAllowAuthorization();

    expect(app(ProviderPortResolver::class)->read(
        new Actor(PrincipalType::SCHEDULER, 43, null, tenantId: $tenantId),
        app(NativePeopleAdapter::class),
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        ProviderScope::tenant(),
    ))->toBeInstanceOf(BootstrapsWorkforce::class);
});

test('the adapter is not registered when the People domain reader bindings are disabled', function (): void {
    $registry = new ProviderRegistry;
    app()->instance(ProviderRegistry::class, $registry);
    app()->offsetUnset(ReadsNativeWorkforceBootstrap::class);
    app()->offsetUnset(ReadsNativeWorkforceChanges::class);

    try {
        (new NativePeopleServiceProvider(app()))->boot();

        expect($registry->find(NativePeopleAdapter::ID))->toBeNull();
    } finally {
        nativePeopleRestoreReaderBindings();
    }
});

test('the adapter is not registered with only one usable People reader binding', function (): void {
    $registry = new ProviderRegistry;
    app()->instance(ProviderRegistry::class, $registry);
    app()->offsetUnset(ReadsNativeWorkforceChanges::class);

    try {
        (new NativePeopleServiceProvider(app()))->boot();

        expect($registry->find(NativePeopleAdapter::ID))->toBeNull();
    } finally {
        nativePeopleRestoreReaderBindings();
    }
});

test('bootstrap maps the published records and preserves opaque paging evidence', function (): void {
    $asOf = new DateTimeImmutable('2026-09-04T02:03:04.000000+00:00');
    $company = nativePeopleCompany($asOf);
    $unit = nativePeopleUnit($company->reference, $asOf);
    $manager = new NativeReference(NativeResourceType::Employee, 'employee:9');
    $user = new NativeReference(NativeResourceType::User, 'user:17');
    $employee = new NativeEmployee(
        reference: new NativeReference(NativeResourceType::Employee, 'employee:10'),
        companyReference: $company->reference,
        displayName: 'Aminah Salleh',
        active: true,
        effectiveAt: $asOf,
        observedAt: $asOf,
        employeeNumber: 'E-010',
        email: 'aminah@example.test',
        userReference: $user,
        organizationReference: $unit->reference,
        managerReference: $manager,
        departmentHeadReference: $manager,
        sourceVersion: '20260904020304000000',
    );
    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldReceive('read')
        ->once()
        ->with(Mockery::on(fn (NativeBootstrapRequest $request): bool => $request->pageCursor === 'opaque-page' && $request->limit === 50))
        ->andReturn(new NativeBootstrapPage(
            employees: [$employee],
            companies: [$company],
            organizationUnits: [$unit],
            asOf: $asOf,
            nextPageCursor: 'opaque-next',
            resumeCursor: null,
            complete: false,
        ));
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    $page = app(NativePeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->bootstrap(new WorkforcePageRequest('opaque-page', 50));

    expect($page->asOf)->toBe($asOf)
        ->and($page->nextPageCursor)->toBe('opaque-next')
        ->and($page->resumeCursor)->toBeNull()
        ->and($page->complete)->toBeFalse()
        ->and($page->positions)->toBe([])
        ->and($page->companies[0]->reference->providerId)->toBe('blb-people')
        ->and($page->companies[0]->code)->toBe('SBG')
        ->and($page->organizationUnits[0]->kind)->toBe('department')
        ->and($page->employees[0])->toBeInstanceOf(WorkforceEmployee::class)
        ->and($page->employees[0]->userReference?->externalId)->toBe('user:17')
        ->and($page->employees[0]->organizationReference?->externalId)->toBe('department:3')
        ->and($page->employees[0]->positionReference)->toBeNull()
        ->and($page->employees[0]->managerReference?->externalId)->toBe('employee:9')
        ->and($page->employees[0]->sourceVersion)->toBe('20260904020304000000');
});

test('incremental reads preserve upserts deactivations and durable cursors', function (): void {
    $since = new DateTimeImmutable('2026-09-04T02:00:00+00:00');
    $asOf = new DateTimeImmutable('2026-09-04T02:03:04+00:00');
    $company = nativePeopleCompany($asOf);
    $reader = Mockery::mock(ReadsNativeWorkforceChanges::class);
    $reader->shouldReceive('read')
        ->once()
        ->with(Mockery::on(fn (NativeChangeRequest $request): bool => $request->resumeCursor === 'resume-1'
            && $request->pageCursor === null && $request->limit === 20))
        ->andReturn(new NativeChangePage(
            changes: [
                new NativeUpsert($company, $asOf),
                new NativeDeactivation($company->reference, $asOf),
            ],
            since: $since,
            asOf: $asOf,
            nextPageCursor: null,
            resumeCursor: 'resume-2',
            complete: true,
        ));
    app()->instance(ReadsNativeWorkforceChanges::class, $reader);

    $page = app(NativePeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->changes(new WorkforceChangeRequest('resume-1', limit: 20));

    expect($page->changes[0])->toBeInstanceOf(WorkforceUpsert::class)
        ->and($page->changes[1])->toBeInstanceOf(WorkforceDeactivation::class)
        ->and($page->changes[1]->reference->externalId)->toBe('company:1')
        ->and($page->asOf)->toBe($asOf)
        ->and($page->resumeCursor)->toBe('resume-2')
        ->and($page->complete)->toBeTrue();
});

test('provider cursor failures cross the adapter boundary without leaking cursor contents', function (): void {
    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldReceive('read')->once()->andThrow(
        InvalidWorkforceBootstrapCursorException::malformed(),
    );
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    try {
        app(NativePeopleAdapter::class)->resolvePort(
            BootstrapsWorkforce::class,
            ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
        )->bootstrap(new WorkforcePageRequest('secret-cursor-value'));
        test()->fail('Expected the provider cursor to be rejected.');
    } catch (ProviderValidationException $exception) {
        expect($exception->providerId)->toBe('blb-people')
            ->and($exception->operation)->toBe('workforce.bootstrap')
            ->and($exception->getMessage())->not->toContain('secret-cursor-value')
            ->and($exception->context)->toBe([]);
    }
});

test('incremental cursor failures retain their validation classification', function (): void {
    $reader = Mockery::mock(ReadsNativeWorkforceChanges::class);
    $reader->shouldReceive('read')->once()->andThrow(
        InvalidWorkforceChangeCursorException::malformed(),
    );
    app()->instance(ReadsNativeWorkforceChanges::class, $reader);

    expect(fn () => app(NativePeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->changes(new WorkforceChangeRequest('secret-resume-value')))
        ->toThrow(ProviderValidationException::class, 'change cursor was rejected');
});

test('missing tenant context remains a fail-closed tenancy exception', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => app(NativePeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->bootstrap(new WorkforcePageRequest))
        ->toThrow(TenantContextMissingException::class);
});

test('a missing provider reader binding is a compatibility failure', function (): void {
    app()->offsetUnset(ReadsNativeWorkforceBootstrap::class);

    try {
        expect(fn () => app(NativePeopleAdapter::class)->resolvePort(
            BootstrapsWorkforce::class,
            ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
        )->bootstrap(new WorkforcePageRequest))
            ->toThrow(ProviderCompatibilityException::class, 'does not match the supported adapter contract');
    } finally {
        nativePeopleRestoreReaderBindings();
    }
});

test('an incompatible provider return type is a compatibility failure', function (): void {
    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldReceive('read')->once()->andReturn(new stdClass);
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    expect(fn () => app(NativePeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->bootstrap(new WorkforcePageRequest))
        ->toThrow(ProviderCompatibilityException::class, 'does not match the supported adapter contract');
});

test('unexpected provider programming failures are not disguised as retryable downtime', function (): void {
    $reader = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $reader->shouldReceive('read')->once()->andThrow(new LogicException('provider implementation defect'));
    app()->instance(ReadsNativeWorkforceBootstrap::class, $reader);

    expect(fn () => app(NativePeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(NativePeopleAdapter::ID),
    )->bootstrap(new WorkforcePageRequest))
        ->toThrow(LogicException::class, 'provider implementation defect');
});

test('the adapter passes conformance using the exact ports it declares', function (): void {
    $asOf = new DateTimeImmutable('2026-09-04T02:03:04+00:00');
    $bootstrap = Mockery::mock(ReadsNativeWorkforceBootstrap::class);
    $bootstrap->shouldReceive('read')->once()->andReturn(new NativeBootstrapPage(
        employees: [],
        companies: [],
        organizationUnits: [],
        asOf: $asOf,
        nextPageCursor: null,
        resumeCursor: 'resume-1',
        complete: true,
    ));
    $changes = Mockery::mock(ReadsNativeWorkforceChanges::class);
    $changes->shouldReceive('read')->once()->andReturn(new NativeChangePage(
        changes: [],
        since: $asOf,
        asOf: $asOf,
        nextPageCursor: null,
        resumeCursor: 'resume-2',
        complete: true,
    ));
    app()->instance(ReadsNativeWorkforceBootstrap::class, $bootstrap);
    app()->instance(ReadsNativeWorkforceChanges::class, $changes);

    expect(ProviderConformance::violations(app(NativePeopleAdapter::class)))->toBe([]);
});

function nativePeopleCompany(DateTimeImmutable $observedAt): NativeCompany
{
    return new NativeCompany(
        reference: new NativeReference(NativeResourceType::Company, 'company:1'),
        name: 'SBG',
        active: true,
        observedAt: $observedAt,
        code: 'SBG',
        sourceVersion: '20260904020304000000',
    );
}

function nativePeopleUnit(NativeReference $company, DateTimeImmutable $observedAt): NativeOrganizationUnit
{
    return new NativeOrganizationUnit(
        reference: new NativeReference(NativeResourceType::OrganizationUnit, 'department:3'),
        companyReference: $company,
        name: 'Human Resources',
        active: true,
        effectiveAt: $observedAt,
        observedAt: $observedAt,
        code: 'HR',
        kind: 'department',
        sourceVersion: '20260904020304000000',
    );
}

function nativePeopleActivateConnection(int $tenantId, ProviderScope $scope): void
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure($scope, NativePeopleAdapter::ID);
    $store->activate((int) $connection->id);
}

function nativePeopleAllowAuthorization(): void
{
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
}

function nativePeopleRestoreReaderBindings(): void
{
    app()->bind(ReadsNativeWorkforceBootstrap::class, NativeWorkforceBootstrapReader::class);
    app()->bind(ReadsNativeWorkforceChanges::class, NativeWorkforceChangeReader::class);
}
