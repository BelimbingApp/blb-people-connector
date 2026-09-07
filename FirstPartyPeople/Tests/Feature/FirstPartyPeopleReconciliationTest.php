<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceCompany as PeopleWorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee as PeopleWorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Enums\WorkforceResourceType as PeopleWorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderPortResolver;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;

/*
 * Self-contained helpers are prefixed fpReconcile. The only outside helper is
 * the platform's createTenantWithCompany().
 */

afterEach(fn () => app(TenantContext::class)->clear());

test('the conformance suite reconciliation branch runs for the first-party adapter', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Reconcile Conformance Tenant'],
        ['name' => 'Reconcile Conformance Company', 'code' => 'RCONF'],
    );
    fpReconcileSeedEmployee($company, 'RC-001', 'Conformance Worker');
    app(TenantContext::class)->set((int) $tenant->id);

    $adapter = app(FirstPartyPeopleAdapter::class);
    $reconcileCalls = 0;

    $violations = ProviderConformance::violations(
        $adapter,
        resolvePort: function (PeopleCapability $capability, string $contract) use ($adapter, &$reconcileCalls): object {
            $port = $adapter->resolvePort(
                $contract,
                App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
            );
            expect($port)->toBeInstanceOf($contract);

            if ($port instanceof ReconcilesWorkforce) {
                return new class($port, $reconcileCalls) implements ReconcilesWorkforce
                {
                    public function __construct(
                        private ReconcilesWorkforce $inner,
                        private int &$calls,
                    ) {}

                    public function reconcile(): ReconciliationReport
                    {
                        $this->calls++;

                        return $this->inner->reconcile();
                    }
                };
            }

            return $port;
        },
    );

    expect($violations)->toBe([])
        ->and($reconcileCalls)->toBeGreaterThan(0)
        ->and($adapter->capabilities()->readPortContracts(PeopleCapability::EmployeeDirectory))
        ->toContain(ReconcilesWorkforce::class);
});

test('after a full sync reconcile reports zero issues and writes nothing', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $before = fpReconcileTableCounts($f['tenantId']);
    $report = fpReconcileViaResolver($f);

    expect($report->differences)->toBe([])
        ->and(fpReconcileTableCounts($f['tenantId']))->toBe($before);
});

test('an active-flag mismatch against People is reported for that employee reference', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $employeeId = (string) $f['employee']->id;
    $real = app(ReadsWorkforceDirectory::class);
    $published = collect($real->employees((string) $f['companyId']))->first(
        fn (PeopleWorkforceEmployee $employee): bool => $employee->reference->externalId === $employeeId,
    );
    expect($published)->not->toBeNull();

    $calls = ['employees' => [], 'organizationUnits' => [], 'company' => []];
    app()->instance(ReadsWorkforceDirectory::class, fpReconcileDirectorySpy($real, $calls, inactiveEmployeeIds: [$employeeId]));

    $before = fpReconcileTableCounts($f['tenantId']);
    $report = fpReconcileViaResolver($f);

    expect($report->differences)->toHaveCount(1)
        ->and($report->differences[0]['code'])->toBe('active_flag_mismatch')
        ->and($report->differences[0]['reference'])->toBe($employeeId)
        ->and($calls['employees'])->toBe([(string) $f['companyId']])
        ->and(fpReconcileTableCounts($f['tenantId']))->toBe($before);
});

test('a projection row with no People counterpart reports missing-in-provider', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $at = new DateTimeImmutable('2026-03-07T12:00:00Z');
    app(WorkforceProjectionStore::class)->upsert($f['connectionId'], new WorkforceEmployee(
        reference: new ExternalReference(FirstPartyPeopleAdapter::ID, WorkforceResourceType::Employee, 'orphan-never-in-people'),
        companyReference: new ExternalReference(FirstPartyPeopleAdapter::ID, WorkforceResourceType::Company, (string) $f['companyId']),
        displayName: 'Orphan Projection',
        active: true,
        effectiveAt: $at,
        observedAt: $at,
    ));

    $report = fpReconcileViaResolver($f);

    expect($report->differences)->toHaveCount(1)
        ->and($report->differences[0]['code'])->toBe('missing_in_provider')
        ->and($report->differences[0]['reference'])->toBe('orphan-never-in-people');
});

test('a People employee never synced reports missing-in-projection', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $unsynced = fpReconcileSeedEmployee($f['company'], 'RC-UNSYNCED', 'Never Synced Worker');

    $report = fpReconcileViaResolver($f);

    expect($report->differences)->toHaveCount(1)
        ->and($report->differences[0]['code'])->toBe('missing_in_projection')
        ->and($report->differences[0]['reference'])->toBe((string) $unsynced->id);
});

test('a sibling company employee is neither read nor reported', function (): void {
    $f = fpReconcileFixture();
    $sibling = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling Co', 'code' => 'SIB']);
    $siblingEmployee = fpReconcileSeedEmployee($sibling, 'SIB-001', 'Sibling Worker');
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $real = app(ReadsWorkforceDirectory::class);
    $calls = ['employees' => [], 'organizationUnits' => [], 'company' => []];
    app()->instance(ReadsWorkforceDirectory::class, fpReconcileDirectorySpy($real, $calls));

    $report = fpReconcileViaResolver($f);

    expect($report->differences)->toBe([])
        ->and($calls['employees'])->toBe([(string) $f['companyId']])
        ->and($calls['organizationUnits'])->toBe([(string) $f['companyId']])
        ->and($calls['company'])->toBe([(string) $f['companyId']])
        ->and(collect($report->differences)->pluck('reference')->all())
        ->not->toContain((string) $siblingEmployee->id);
});

test('a sibling tenant connection is refused before any People read', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    [$otherTenant] = createTenantWithCompany(['name' => 'Other Reconcile Tenant']);
    $real = app(ReadsWorkforceDirectory::class);
    $calls = ['employees' => [], 'organizationUnits' => [], 'company' => []];
    app()->instance(ReadsWorkforceDirectory::class, fpReconcileDirectorySpy($real, $calls));

    $port = app(ProviderPortResolver::class)->read(
        $f['actor'],
        fpReconcileAdapter(),
        PeopleCapability::EmployeeDirectory,
        ReconcilesWorkforce::class,
        ProviderScope::company($f['companyId']),
    );

    app(TenantContext::class)->set((int) $otherTenant->id);

    expect(fn () => $port->reconcile())
        ->toThrow(App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException::class)
        ->and($calls['employees'])->toBe([])
        ->and($calls['organizationUnits'])->toBe([])
        ->and($calls['company'])->toBe([]);
});

test('authorization without provider directory read is refused and People is never invoked', function (): void {
    $f = fpReconcileFixture();
    app(WorkforceSyncRunner::class)->bootstrap($f['actor'], fpReconcileAdapter(), $f['connectionId']);

    $real = app(ReadsWorkforceDirectory::class);
    $calls = ['employees' => [], 'organizationUnits' => [], 'company' => []];
    app()->instance(ReadsWorkforceDirectory::class, fpReconcileDirectorySpy($real, $calls));

    app()->instance(AuthorizationService::class, new class implements AuthorizationService
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
    });

    expect(fn () => app(ProviderPortResolver::class)->read(
        $f['actor'],
        fpReconcileAdapter(),
        PeopleCapability::EmployeeDirectory,
        ReconcilesWorkforce::class,
        ProviderScope::company($f['companyId']),
    ))->toThrow(AuthorizationDeniedException::class)
        ->and($calls['employees'])->toBe([])
        ->and($calls['organizationUnits'])->toBe([])
        ->and($calls['company'])->toBe([]);
});

/**
 * @return array{
 *     tenantId: int,
 *     companyId: int,
 *     company: Company,
 *     employee: Employee,
 *     connectionId: int,
 *     actor: Actor
 * }
 */
function fpReconcileFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Reconcile Tenant'],
        ['name' => 'Reconcile Company', 'code' => 'RCON'],
    );
    $employee = fpReconcileSeedEmployee($company, 'RC-100', 'Reconcile Worker');
    app(TenantContext::class)->set((int) $tenant->id);
    fpReconcileAllowEverything();

    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate(
        (int) $store->configure(ProviderScope::company((int) $company->id), FirstPartyPeopleAdapter::ID)->id,
    );
    $actor = app(SchedulerPrincipal::class)->forConnection($connection);

    return [
        'tenantId' => (int) $tenant->id,
        'companyId' => (int) $company->id,
        'company' => $company,
        'employee' => $employee,
        'connectionId' => (int) $connection->id,
        'actor' => $actor,
    ];
}

function fpReconcileSeedEmployee(Company $company, string $number, string $name): Employee
{
    // Bootstrap projects Core Department rows as organization units, while
    // ReadsWorkforceDirectory publishes PeopleReferenceEntry units. Leaving
    // department_id null keeps the post-sync projection set aligned with the
    // directory contracts reconcile reads.
    return Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => null,
        'employee_number' => $number,
        'full_name' => $name,
        'employee_type' => 'full_time',
        'status' => 'active',
    ]);
}

function fpReconcileAllowEverything(): void
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

function fpReconcileAdapter(): FirstPartyPeopleAdapter
{
    return app(FirstPartyPeopleAdapter::class);
}

function fpReconcileViaResolver(array $f): ReconciliationReport
{
    /** @var ReconcilesWorkforce $port */
    $port = app(ProviderPortResolver::class)->read(
        $f['actor'],
        fpReconcileAdapter(),
        PeopleCapability::EmployeeDirectory,
        ReconcilesWorkforce::class,
        ProviderScope::company($f['companyId']),
    );

    return $port->reconcile();
}

/**
 * @return array{projections: int, issues: int}
 */
function fpReconcileTableCounts(int $tenantId): array
{
    $projections = WorkforceCompanyProjection::query()->withoutCompanyScope('Test: whole-tenant row count.')->forTenant($tenantId)->count()
        + WorkforceOrganizationUnitProjection::query()->withoutCompanyScope('Test: whole-tenant row count.')->forTenant($tenantId)->count()
        + WorkforcePositionProjection::query()->withoutCompanyScope('Test: whole-tenant row count.')->forTenant($tenantId)->count()
        + WorkforceEmployeeProjection::query()->withoutCompanyScope('Test: whole-tenant row count.')->forTenant($tenantId)->count();

    return [
        'projections' => $projections,
        'issues' => ReconciliationIssue::query()->forTenant($tenantId)->count(),
    ];
}

/**
 * @param  array{employees: list<string>, organizationUnits: list<string>, company: list<string>}  $calls
 * @param  list<string>  $inactiveEmployeeIds
 */
function fpReconcileDirectorySpy(ReadsWorkforceDirectory $inner, array &$calls, array $inactiveEmployeeIds = []): ReadsWorkforceDirectory
{
    return new class($inner, $calls, $inactiveEmployeeIds) implements ReadsWorkforceDirectory
    {
        /** @param  array{employees: list<string>, organizationUnits: list<string>, company: list<string>}  $calls */
        public function __construct(
            private ReadsWorkforceDirectory $inner,
            private array &$calls,
            private array $inactiveEmployeeIds,
        ) {}

        public function companyForPlatform(int $platformCompanyId): ?PeopleWorkforceCompany
        {
            return $this->inner->companyForPlatform($platformCompanyId);
        }

        public function company(string $companyStableId): ?PeopleWorkforceCompany
        {
            $this->calls['company'][] = $companyStableId;

            return $this->inner->company($companyStableId);
        }

        public function employees(string $companyStableId): array
        {
            $this->calls['employees'][] = $companyStableId;

            return array_map(function (PeopleWorkforceEmployee $employee): PeopleWorkforceEmployee {
                if (! in_array($employee->reference->externalId, $this->inactiveEmployeeIds, true)) {
                    return $employee;
                }

                return new PeopleWorkforceEmployee(
                    reference: $employee->reference,
                    companyReference: $employee->companyReference,
                    displayName: $employee->displayName,
                    active: false,
                    effectiveAt: $employee->effectiveAt,
                    observedAt: $employee->observedAt,
                    employeeNumber: $employee->employeeNumber,
                    email: $employee->email,
                    userReference: $employee->userReference,
                    organizationReference: $employee->organizationReference,
                    positionReference: $employee->positionReference,
                    managerReference: $employee->managerReference,
                    departmentHeadReference: $employee->departmentHeadReference,
                    sourceVersion: $employee->sourceVersion,
                    userReferenceRevoked: $employee->userReferenceRevoked,
                );
            }, $this->inner->employees($companyStableId));
        }

        public function organizationUnits(string $companyStableId): array
        {
            $this->calls['organizationUnits'][] = $companyStableId;

            return $this->inner->organizationUnits($companyStableId);
        }

        public function employeeForUser(string $companyStableId, int $platformUserId): ?PeopleWorkforceEmployee
        {
            return $this->inner->employeeForUser($companyStableId, $platformUserId);
        }

        public function remap(
            PeopleWorkforceResourceType $type,
            string $fromStableId,
            string $toStableId,
        ): ?WorkforceRemapFact {
            return $this->inner->remap($type, $fromStableId, $toStableId);
        }
    };
}
