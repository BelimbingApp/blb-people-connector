<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsPeopleWorkforceChanges;
use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceChangePage as PeopleWorkforceChangePage;
use App\Domains\People\Provider\Data\WorkforceChangeRequest as PeopleWorkforceChangeRequest;
use App\Domains\People\Provider\Data\WorkforceCompany as PeopleWorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceDeactivation as PeopleWorkforceDeactivation;
use App\Domains\People\Provider\Data\WorkforceEmployee as PeopleWorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit as PeopleWorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceUpsert as PeopleWorkforceUpsert;
use App\Domains\People\Provider\Enums\WorkforceResourceType as PeopleWorkforceResourceType;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
use App\Domains\PeopleConnector\Connector\Contracts\AuthenticatesProvider;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ImportsWorkforceFiles;
use App\Domains\PeopleConnector\Connector\Contracts\ProvidesProviderUiHandoff;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderUnknownOutcomeException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\Connector\Services\ProviderCommandReconciler;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;

test('the first-party adapter declares only the published company, organization and employee reads', function (): void {
    $adapter = app(FirstPartyPeopleAdapter::class);
    $capabilities = $adapter->capabilities();

    expect($adapter->descriptor()->id)->toBe('blb-people')
        ->and($adapter->descriptor()->contractVersion)->toBe(WorkforceBootstrapPage::CONTRACT_VERSION)
        ->and($capabilities->direction(PeopleCapability::CompanyDirectory))->toBe(CapabilityDirection::Read)
        ->and($capabilities->direction(PeopleCapability::OrganizationDirectory))->toBe(CapabilityDirection::Read)
        ->and($capabilities->direction(PeopleCapability::EmployeeDirectory))->toBe(CapabilityDirection::Read)
        ->and($capabilities->deliveries(PeopleCapability::EmployeeDirectory))->toBe([CapabilityDelivery::Synchronous]);

    // Everything People has not published stays undeclared, in both directions.
    foreach ([
        PeopleCapability::UserDirectory,
        PeopleCapability::ManagerHierarchy,
        PeopleCapability::Payroll,
        PeopleCapability::Attendance,
        PeopleCapability::Leave,
        PeopleCapability::Claims,
        PeopleCapability::Training,
        PeopleCapability::Documents,
        PeopleCapability::SingleSignOn,
    ] as $capability) {
        expect($capabilities->direction($capability))->toBe(CapabilityDirection::None);
    }

    foreach ([PeopleCapability::CompanyDirectory, PeopleCapability::OrganizationDirectory, PeopleCapability::EmployeeDirectory] as $capability) {
        expect($capabilities->writePortContracts($capability))->toBe([]);
    }
});

test('the adapter resolves its two declared read ports and nothing else', function (): void {
    $adapter = app(FirstPartyPeopleAdapter::class);
    $authorization = ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID);

    expect($adapter->resolvePort(BootstrapsWorkforce::class, $authorization))->toBeInstanceOf(BootstrapsWorkforce::class)
        ->and($adapter->resolvePort(ReadsWorkforceChanges::class, $authorization))->toBeInstanceOf(ReadsWorkforceChanges::class);

    foreach ([
        ReconcilesWorkforce::class,
        ImportsWorkforceFiles::class,
        AuthenticatesProvider::class,
        ProvidesProviderUiHandoff::class,
    ] as $contract) {
        expect($adapter->resolvePort($contract, $authorization))->toBeNull();
    }
});

test('the adapter passes the connector conformance suite against native People data', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Adapter Tenant'],
        ['name' => 'Adapter Company', 'code' => 'ADAPTER'],
    );
    $departmentType = DepartmentType::query()->create([
        'code' => 'adapter-engineering',
        'name' => 'Engineering',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);
    Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'employee_number' => 'A-001',
        'full_name' => 'Adapter Worker',
        'employee_type' => 'full_time',
    ]);
    app(TenantContext::class)->set((int) $tenant->id);

    $adapter = app(FirstPartyPeopleAdapter::class);
    $registry = new ProviderRegistry;
    $registry->register($adapter);

    expect($registry->find(FirstPartyPeopleAdapter::ID))->toBe($adapter)
        ->and($adapter->health()->state)->toBe(ProviderHealthState::Healthy)
        ->and(ProviderConformance::violations($adapter))->toBe([]);
});

test('bootstrap pages translate every published record and preserve provider cursors verbatim', function (): void {
    $observedAt = new DateTimeImmutable('2026-03-01T08:00:00.000000+00:00');
    $companyReference = new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'company:7');
    $page = new WorkforceBootstrapPage(
        employees: [new PeopleWorkforceEmployee(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::Employee, 'employee:11'),
            companyReference: $companyReference,
            displayName: 'Published Worker',
            active: true,
            effectiveAt: $observedAt,
            observedAt: $observedAt,
            employeeNumber: 'P-011',
            email: 'worker@published.test',
            organizationReference: new PeopleExternalReference(PeopleWorkforceResourceType::OrganizationUnit, 'department:3'),
            managerReference: new PeopleExternalReference(PeopleWorkforceResourceType::Employee, 'employee:9'),
            sourceVersion: 'v-11',
        )],
        companies: [new PeopleWorkforceCompany(
            reference: $companyReference,
            name: 'Published Company',
            active: true,
            observedAt: $observedAt,
            code: 'PUB',
            sourceVersion: 'v-7',
        )],
        organizationUnits: [new PeopleWorkforceOrganizationUnit(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::OrganizationUnit, 'department:3'),
            companyReference: $companyReference,
            name: 'Published Engineering',
            active: true,
            effectiveAt: $observedAt,
            observedAt: $observedAt,
            code: 'ENG',
            kind: 'operational',
            sourceVersion: 'v-3',
        )],
        asOf: $observedAt,
        nextPageCursor: null,
        resumeCursor: 'opaque-resume-cursor',
        complete: true,
    );
    app()->instance(ReadsWorkforceBootstrap::class, new class($page) implements ReadsWorkforceBootstrap
    {
        public ?WorkforceBootstrapRequest $received = null;

        public function __construct(private readonly WorkforceBootstrapPage $page) {}

        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            $this->received = $request;

            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );
    $translated = $port->bootstrap(new WorkforcePageRequest('opaque-page-cursor', limit: 17));

    expect($translated->complete)->toBeTrue()
        ->and($translated->resumeCursor)->toBe('opaque-resume-cursor')
        ->and($translated->nextPageCursor)->toBeNull()
        ->and($translated->asOf)->toEqual($observedAt)
        ->and($translated->positions)->toBe([])
        ->and(app(ReadsWorkforceBootstrap::class)->received->pageCursor)->toBe('opaque-page-cursor')
        ->and(app(ReadsWorkforceBootstrap::class)->received->limit)->toBe(17);

    $translatedCompany = $translated->companies[0];
    expect($translatedCompany->reference->providerId)->toBe('blb-people')
        ->and($translatedCompany->reference->resourceType)->toBe(WorkforceResourceType::Company)
        ->and($translatedCompany->reference->externalId)->toBe('company:7')
        ->and($translatedCompany->name)->toBe('Published Company')
        ->and($translatedCompany->code)->toBe('PUB')
        ->and($translatedCompany->sourceVersion)->toBe('v-7')
        ->and($translatedCompany->active)->toBeTrue()
        ->and($translatedCompany->observedAt)->toEqual($observedAt);

    $translatedUnit = $translated->organizationUnits[0];
    expect($translatedUnit->reference->externalId)->toBe('department:3')
        ->and($translatedUnit->companyReference->externalId)->toBe('company:7')
        ->and($translatedUnit->parentReference)->toBeNull()
        ->and($translatedUnit->name)->toBe('Published Engineering')
        ->and($translatedUnit->code)->toBe('ENG')
        ->and($translatedUnit->kind)->toBe('operational')
        ->and($translatedUnit->sourceVersion)->toBe('v-3')
        ->and($translatedUnit->effectiveAt)->toEqual($observedAt);

    $translatedEmployee = $translated->employees[0];
    expect($translatedEmployee)->toBeInstanceOf(WorkforceEmployee::class)
        ->and($translatedEmployee->reference->externalId)->toBe('employee:11')
        ->and($translatedEmployee->companyReference->externalId)->toBe('company:7')
        ->and($translatedEmployee->organizationReference->externalId)->toBe('department:3')
        ->and($translatedEmployee->managerReference->externalId)->toBe('employee:9')
        ->and($translatedEmployee->displayName)->toBe('Published Worker')
        ->and($translatedEmployee->employeeNumber)->toBe('P-011')
        ->and($translatedEmployee->email)->toBe('worker@published.test')
        ->and($translatedEmployee->sourceVersion)->toBe('v-11')
        ->and($translatedEmployee->positionReference)->toBeNull()
        ->and($translatedEmployee->userReference)->toBeNull()
        ->and($translatedEmployee->userReferenceRevoked)->toBeFalse();
});

test('an employee whose provider link was positively revoked stays revoked through translation', function (): void {
    $observedAt = new DateTimeImmutable('2026-03-02T08:00:00.000000+00:00');
    $companyReference = new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'company:7');
    $page = new WorkforceBootstrapPage(
        employees: [new PeopleWorkforceEmployee(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::Employee, 'employee:12'),
            companyReference: $companyReference,
            displayName: 'Revoked Worker',
            active: true,
            effectiveAt: $observedAt,
            observedAt: $observedAt,
            userReferenceRevoked: true,
        )],
        companies: [],
        organizationUnits: [],
        asOf: $observedAt,
        nextPageCursor: null,
        resumeCursor: 'resume',
        complete: true,
    );
    app()->instance(ReadsWorkforceBootstrap::class, new class($page) implements ReadsWorkforceBootstrap
    {
        public function __construct(private readonly WorkforceBootstrapPage $page) {}

        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    expect($port->bootstrap(new WorkforcePageRequest)->employees[0]->userReferenceRevoked)->toBeTrue();
});

test('incremental pages translate upserts and deactivations and preserve both cursors', function (): void {
    $occurredAt = new DateTimeImmutable('2026-03-03T09:30:00.000000+00:00');
    $companyReference = new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'company:7');
    $page = new PeopleWorkforceChangePage(
        changes: [
            new PeopleWorkforceUpsert(
                record: new PeopleWorkforceCompany(
                    reference: $companyReference,
                    name: 'Renamed Company',
                    active: true,
                    observedAt: $occurredAt,
                ),
                occurredAt: $occurredAt,
            ),
            new PeopleWorkforceDeactivation(
                reference: new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'company:8'),
                occurredAt: $occurredAt,
            ),
        ],
        since: $occurredAt,
        asOf: $occurredAt,
        nextPageCursor: 'next-change-page',
        resumeCursor: null,
        complete: false,
    );
    app()->instance(ReadsPeopleWorkforceChanges::class, new class($page) implements ReadsPeopleWorkforceChanges
    {
        public ?PeopleWorkforceChangeRequest $received = null;

        public function __construct(private readonly PeopleWorkforceChangePage $page) {}

        public function read(PeopleWorkforceChangeRequest $request): PeopleWorkforceChangePage
        {
            $this->received = $request;

            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );
    $translated = $port->changes(new WorkforceChangeRequest('resume-cursor', 'change-page-cursor', limit: 5));

    expect($translated->complete)->toBeFalse()
        ->and($translated->nextPageCursor)->toBe('next-change-page')
        ->and($translated->resumeCursor)->toBeNull()
        ->and($translated->asOf)->toEqual($occurredAt)
        ->and(app(ReadsPeopleWorkforceChanges::class)->received->resumeCursor)->toBe('resume-cursor')
        ->and(app(ReadsPeopleWorkforceChanges::class)->received->pageCursor)->toBe('change-page-cursor')
        ->and(app(ReadsPeopleWorkforceChanges::class)->received->limit)->toBe(5)
        ->and($translated->changes[0])->toBeInstanceOf(WorkforceUpsert::class)
        ->and($translated->changes[0]->record->reference->providerId)->toBe('blb-people')
        ->and($translated->changes[0]->record->name)->toBe('Renamed Company')
        ->and($translated->changes[0]->occurredAt)->toEqual($occurredAt)
        ->and($translated->changes[1])->toBeInstanceOf(WorkforceDeactivation::class)
        ->and($translated->changes[1]->reference->externalId)->toBe('company:8')
        ->and($translated->changes[1]->reference->resourceType)->toBe(WorkforceResourceType::Company)
        ->and($translated->changes[1]->occurredAt)->toEqual($occurredAt);
});

test('provider cursor and projection failures surface as connector validation exceptions', function (): void {
    app()->instance(ReadsWorkforceBootstrap::class, new class implements ReadsWorkforceBootstrap
    {
        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            throw InvalidWorkforceBootstrapCursorException::forDifferentTenant();
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    expect(fn () => $port->bootstrap(new WorkforcePageRequest))
        ->toThrow(ProviderValidationException::class);
});

test('a missing tenant context stays the provider decision and is not translated away', function (): void {
    app(TenantContext::class)->clear();

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    expect(fn () => $port->bootstrap(new WorkforcePageRequest))
        ->toThrow(TenantContextMissingException::class);
});

test('the adapter module touches only the published People provider surface', function (): void {
    $module = dirname(__DIR__, 2);
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module)) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Tests/')) {
            continue;
        }

        preg_match_all('/App\\\\Domains\\\\People\\\\[A-Za-z\\\\]+/', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[0] as $symbol) {
            if (preg_match('/^App\\\\Domains\\\\People\\\\Provider\\\\(Contracts|Data|Enums|Exceptions)\\\\/', $symbol) !== 1) {
                $offenders[] = $symbol;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

test('the module registers the adapter only for a deployment that names it the active provider', function (?string $activeProvider, bool $expected): void {
    app()->forgetInstance(ProviderRegistry::class);
    config()->set('people-connector.active_provider', $activeProvider);

    $found = app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID);

    expect($found instanceof FirstPartyPeopleAdapter)->toBe($expected);
})->with([
    'named as active' => ['blb-people', true],
    'another provider is active' => ['hr2000.sbg', false],
    'no provider configured' => [null, false],
]);

test('every published upsert record kind survives the incremental translation', function (): void {
    $occurredAt = new DateTimeImmutable('2026-03-04T11:00:00.000000+00:00');
    $companyReference = new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'company:7');
    $page = new PeopleWorkforceChangePage(
        changes: [
            new PeopleWorkforceUpsert(
                record: new PeopleWorkforceOrganizationUnit(
                    reference: new PeopleExternalReference(PeopleWorkforceResourceType::OrganizationUnit, 'department:4'),
                    companyReference: $companyReference,
                    name: 'Moved Engineering',
                    active: true,
                    effectiveAt: $occurredAt,
                    observedAt: $occurredAt,
                ),
                occurredAt: $occurredAt,
            ),
            new PeopleWorkforceUpsert(
                record: new PeopleWorkforceEmployee(
                    reference: new PeopleExternalReference(PeopleWorkforceResourceType::Employee, 'employee:13'),
                    companyReference: $companyReference,
                    displayName: 'Promoted Worker',
                    active: true,
                    effectiveAt: $occurredAt,
                    observedAt: $occurredAt,
                ),
                occurredAt: $occurredAt,
            ),
        ],
        since: $occurredAt,
        asOf: $occurredAt,
        nextPageCursor: null,
        resumeCursor: 'advanced-resume',
        complete: true,
    );
    app()->instance(ReadsPeopleWorkforceChanges::class, new class($page) implements ReadsPeopleWorkforceChanges
    {
        public function __construct(private readonly PeopleWorkforceChangePage $page) {}

        public function read(PeopleWorkforceChangeRequest $request): PeopleWorkforceChangePage
        {
            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );
    $translated = $port->changes(new WorkforceChangeRequest('resume-cursor'));

    expect($translated->resumeCursor)->toBe('advanced-resume')
        ->and($translated->changes[0]->record)->toBeInstanceOf(WorkforceOrganizationUnit::class)
        ->and($translated->changes[0]->record->reference->externalId)->toBe('department:4')
        ->and($translated->changes[0]->record->name)->toBe('Moved Engineering')
        ->and($translated->changes[1]->record)->toBeInstanceOf(WorkforceEmployee::class)
        ->and($translated->changes[1]->record->reference->externalId)->toBe('employee:13')
        ->and($translated->changes[1]->record->displayName)->toBe('Promoted Worker');
});

test('a refused incremental read is translated at the boundary too', function (): void {
    app()->instance(ReadsPeopleWorkforceChanges::class, new class implements ReadsPeopleWorkforceChanges
    {
        public function read(PeopleWorkforceChangeRequest $request): PeopleWorkforceChangePage
        {
            throw InvalidWorkforceChangeCursorException::forDifferentTenant();
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    expect(fn () => $port->changes(new WorkforceChangeRequest('resume-cursor')))
        ->toThrow(ProviderValidationException::class);
});

test('a published reference naming another provider is refused, not relabelled', function (): void {
    $observedAt = new DateTimeImmutable('2026-03-05T10:00:00.000000+00:00');
    // People can publish a provider identity since blb-people#116. This adapter
    // *is* blb-people, so a reference naming someone else is a contract
    // violation from here, and the connector must not mint an identity under
    // the wrong provider.
    $page = new WorkforceBootstrapPage(
        employees: [],
        companies: [new PeopleWorkforceCompany(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'c-1', 'hr2000.sbg'),
            name: 'Foreign Company',
            active: true,
            observedAt: $observedAt,
        )],
        organizationUnits: [],
        asOf: $observedAt,
        nextPageCursor: null,
        resumeCursor: 'resume',
        complete: true,
    );
    app()->instance(ReadsWorkforceBootstrap::class, new class($page) implements ReadsWorkforceBootstrap
    {
        public function __construct(private readonly WorkforceBootstrapPage $page) {}

        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    try {
        $port->bootstrap(new WorkforcePageRequest);
        expect(false)->toBeTrue('expected ProviderValidationException');
    } catch (ProviderValidationException $exception) {
        expect($exception->context['published_provider_id'] ?? null)->toBe('hr2000.sbg')
            ->and($exception->getMessage())->not->toContain('c-1')
            ->and(json_encode($exception->context))->not->toContain('c-1');
    }
});

test('a published reference naming this adapter still translates', function (): void {
    $observedAt = new DateTimeImmutable('2026-03-05T10:00:00.000000+00:00');
    $page = new WorkforceBootstrapPage(
        employees: [],
        companies: [new PeopleWorkforceCompany(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'c-1', 'blb-people'),
            name: 'Native Company',
            active: true,
            observedAt: $observedAt,
        )],
        organizationUnits: [],
        asOf: $observedAt,
        nextPageCursor: null,
        resumeCursor: 'resume',
        complete: true,
    );
    app()->instance(ReadsWorkforceBootstrap::class, new class($page) implements ReadsWorkforceBootstrap
    {
        public function __construct(private readonly WorkforceBootstrapPage $page) {}

        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        BootstrapsWorkforce::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    expect($port->bootstrap(new WorkforcePageRequest)->companies[0]->reference->providerId)->toBe('blb-people');
});

test('the incremental port refuses a foreign provider reference at its own boundary', function (): void {
    $occurredAt = new DateTimeImmutable('2026-03-05T11:00:00.000000+00:00');
    $page = new PeopleWorkforceChangePage(
        changes: [new PeopleWorkforceDeactivation(
            reference: new PeopleExternalReference(PeopleWorkforceResourceType::Company, 'c-9', 'hr2000.sbg'),
            occurredAt: $occurredAt,
        )],
        since: $occurredAt,
        asOf: $occurredAt,
        nextPageCursor: null,
        resumeCursor: 'resume',
        complete: true,
    );
    app()->instance(ReadsPeopleWorkforceChanges::class, new class($page) implements ReadsPeopleWorkforceChanges
    {
        public function __construct(private readonly PeopleWorkforceChangePage $page) {}

        public function read(PeopleWorkforceChangeRequest $request): PeopleWorkforceChangePage
        {
            return $this->page;
        }
    });

    $port = app(FirstPartyPeopleAdapter::class)->resolvePort(
        ReadsWorkforceChanges::class,
        ProviderPortAuthorization::forConformance(FirstPartyPeopleAdapter::ID),
    );

    try {
        $port->changes(new WorkforceChangeRequest('resume-cursor'));
        expect(false)->toBeTrue('expected ProviderValidationException');
    } catch (ProviderValidationException $exception) {
        expect($exception->context['published_provider_id'] ?? null)->toBe('hr2000.sbg')
            ->and($exception->getMessage())->not->toContain('c-9')
            ->and(json_encode($exception->context))->not->toContain('c-9');
    }
});

test('an unknown command outcome against the co-located adapter refuses rather than retrying', function (): void {
    // The first-party adapter is in-process: a command either completes or
    // throws, so no unknown window exists to reconcile. It therefore does not
    // implement ReconcilesProviderCommands, and the reconciler must refuse
    // rather than read that absence as "not delivered" and send it again.
    expect(fn () => app(ProviderCommandReconciler::class)->settle(
        CommandOutcome::unknown('idem-colocated'),
        app(FirstPartyPeopleAdapter::class),
    ))->toThrow(ProviderUnknownOutcomeException::class);
});

test('a settled outcome needs no reconciliation from the co-located adapter', function (): void {
    $accepted = CommandOutcome::deliveredAccepted('idem-colocated-2', 'ref');

    expect(app(ProviderCommandReconciler::class)->settle($accepted, app(FirstPartyPeopleAdapter::class)))
        ->toBe($accepted);
});
