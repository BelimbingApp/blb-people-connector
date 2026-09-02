<?php

use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\WorkforceSource;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileImportResult;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileInspection;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceMerge;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;

interface TestEmployeeCommandPort extends WritableProviderPort {}

function conformingPeopleProvider(string $id = 'test.provider', string $contract = '1.0.0'): ProviderAdapter
{
    return new class($id, $contract) implements ProviderAdapter
    {
        private WorkforceSource $source;

        public function __construct(private string $id, private string $contract)
        {
            $this->source = new class implements WorkforceSource
            {
                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    return new WorkforcePage(
                        [],
                        new DateTimeImmutable,
                        resumeCursor: 'fixture-checkpoint-1',
                        complete: true,
                    );
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    return new WorkforceChangePage(
                        [],
                        new DateTimeImmutable,
                        resumeCursor: 'fixture-checkpoint-2',
                        complete: true,
                    );
                }

                public function reconcile(): ReconciliationReport
                {
                    return new ReconciliationReport(new DateTimeImmutable, []);
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor($this->id, 'Test Provider', '0.1.0', $this->contract);
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                    new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
                    new CapabilityChannel(CapabilityDelivery::Synchronous, ReconcilesWorkforce::class),
                    new CapabilityChannel(
                        CapabilityDelivery::ProviderUi,
                        providerUiUrl: 'https://provider.example/employees',
                    ),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-08-31T00:00:00+00:00'));
        }

        public function resolvePort(string $contract): ?object
        {
            return $this->source instanceof $contract ? $this->source : null;
        }
    };
}

test('registry accepts one compatible adapter and resolves the configured active provider', function (): void {
    config()->set('people-connector.supported_contract_major', 1);
    config()->set('people-connector.active_provider', 'test.provider');
    $registry = new ProviderRegistry;
    $provider = conformingPeopleProvider();

    $registry->register($provider);

    expect($registry->all())->toBe([$provider])
        ->and($registry->active())->toBe($provider)
        ->and($registry->find('test.provider'))->toBe($provider)
        ->and(ProviderConformance::violations($provider))->toBe([]);
});

test('registry rejects incompatible contract majors and duplicate provider identities', function (): void {
    config()->set('people-connector.supported_contract_major', 1);
    $registry = new ProviderRegistry;

    expect(fn () => $registry->register(conformingPeopleProvider(contract: '2.0.0')))
        ->toThrow(ProviderCompatibilityException::class, 'incompatible');

    $registry->register(conformingPeopleProvider());

    expect(fn () => $registry->register(conformingPeopleProvider()))
        ->toThrow(ProviderCompatibilityException::class, 'already registered');
});

test('provider descriptors enforce semantic versions', function (string $version): void {
    expect(fn () => new ProviderDescriptor('test.provider', 'Test', '1.0.0', $version))
        ->toThrow(InvalidArgumentException::class, 'semantic version');
})->with(['1garbage', 'v1.0.0', '1.0.0-..', '1.0.0-01']);

test('capabilities default to unsupported and aggregate independent delivery channels', function (): void {
    $capabilities = conformingPeopleProvider()->capabilities();

    expect($capabilities->direction(PeopleCapability::EmployeeDirectory))->toBe(CapabilityDirection::Read)
        ->and($capabilities->canWrite(PeopleCapability::EmployeeDirectory))->toBeFalse()
        ->and($capabilities->direction(PeopleCapability::Training))->toBe(CapabilityDirection::None)
        ->and($capabilities->deliveries(PeopleCapability::EmployeeDirectory))
        ->toBe([CapabilityDelivery::Synchronous, CapabilityDelivery::ProviderUi])
        ->and($capabilities->providerUiUrls(PeopleCapability::EmployeeDirectory))
        ->toBe(['https://provider.example/employees']);
});

test('a capability can combine file reads with a provider UI handoff', function (): void {
    $capabilities = new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::FileExchange, BootstrapsWorkforce::class),
            new CapabilityChannel(
                CapabilityDelivery::ProviderUi,
                providerUiUrl: '/provider/employees',
            ),
        ]),
    ]);

    expect($capabilities->canRead(PeopleCapability::EmployeeDirectory))->toBeTrue()
        ->and($capabilities->canWrite(PeopleCapability::EmployeeDirectory))->toBeFalse()
        ->and($capabilities->deliveries(PeopleCapability::EmployeeDirectory))
        ->toBe([CapabilityDelivery::FileExchange, CapabilityDelivery::ProviderUi]);
});

test('write capability names the narrow executable port instead of a generic command claim', function (): void {
    $capabilities = new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeCommandPort::class),
        ]),
    ]);

    expect($capabilities->canWrite(PeopleCapability::EmployeeDirectory))->toBeTrue()
        ->and($capabilities->writePortContracts(PeopleCapability::EmployeeDirectory))
        ->toBe([TestEmployeeCommandPort::class]);
});

test('provider UI channels require a safe handoff URL and carry no data direction', function (): void {
    expect(fn () => new CapabilityChannel(CapabilityDelivery::ProviderUi))
        ->toThrow(InvalidArgumentException::class, 'require an HTTPS URL');

    expect(fn () => new CapabilityChannel(
        CapabilityDelivery::ProviderUi,
        providerUiUrl: 'http://provider.example/payroll',
    ))->toThrow(InvalidArgumentException::class, 'require an HTTPS URL');

    expect(fn () => new CapabilityChannel(
        CapabilityDelivery::ProviderUi,
        providerUiUrl: 'https://user:secret@provider.example/payroll',
    ))->toThrow(InvalidArgumentException::class, 'require an HTTPS URL');

    expect(fn () => new CapabilityChannel(
        CapabilityDelivery::ProviderUi,
        BootstrapsWorkforce::class,
        providerUiUrl: '/provider/payroll',
    ))->toThrow(InvalidArgumentException::class, 'cannot expose');

    expect(fn () => new CapabilityChannel(CapabilityDelivery::Synchronous))
        ->toThrow(InvalidArgumentException::class, 'port interface');
});

test('workforce pages separate page continuation from durable resume checkpoints', function (): void {
    $when = new DateTimeImmutable;

    expect(fn () => new WorkforcePage([], $when, complete: false))
        ->toThrow(InvalidArgumentException::class, 'next page cursor');
    expect(fn () => new WorkforcePage([], $when, nextPageCursor: '', complete: false))
        ->toThrow(InvalidArgumentException::class, 'next page cursor');
    expect(fn () => new WorkforcePage([], $when, nextPageCursor: 'page-2', resumeCursor: 'too-early'))
        ->toThrow(InvalidArgumentException::class, 'Only a complete');
    expect(fn () => new WorkforcePage([], $when, complete: true))
        ->toThrow(InvalidArgumentException::class, 'durable resume cursor');
    expect(fn () => new WorkforcePage([], $when, nextPageCursor: 'unused', resumeCursor: 'checkpoint', complete: true))
        ->toThrow(InvalidArgumentException::class, 'next page cursor');

    $complete = new WorkforcePage([], $when, resumeCursor: 'checkpoint', complete: true);

    expect($complete->resumeCursor)->toBe('checkpoint');
});

test('provider conformance exercises only the narrow ports an adapter declares', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $source = Mockery::mock(WorkforceSource::class);

    $provider->shouldReceive('descriptor')->andReturn(
        new ProviderDescriptor('test.provider', 'Test Provider', '1.0.0', '1.0.0'),
    );
    $provider->shouldReceive('health')->once()->andThrow(new RuntimeException('health'));
    $provider->shouldReceive('capabilities')->andReturn(
        new CapabilitySet([
            new CapabilityDeclaration(PeopleCapability::CompanyDirectory, [
                new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
                new CapabilityChannel(CapabilityDelivery::Synchronous, ReconcilesWorkforce::class),
            ]),
        ]),
    );
    $provider->shouldReceive('resolvePort')->times(3)->andReturn($source);
    $source->shouldReceive('bootstrap')->once()->andReturn(
        new WorkforcePage([], new DateTimeImmutable, resumeCursor: 'checkpoint', complete: true),
    );
    $source->shouldReceive('changes')->once()->andThrow(new RuntimeException('changes'));
    $source->shouldReceive('reconcile')->once()->andThrow(new RuntimeException('reconcile'));

    expect(ProviderConformance::violations($provider))->toBe([
        'provider_health_failed',
        'workforce_changes_failed',
        'workforce_reconciliation_failed',
    ]);
});

test('snapshot-only adapters are not forced to provide incremental or reconciliation ports', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $bootstrap = Mockery::mock(BootstrapsWorkforce::class);

    $provider->shouldReceive('descriptor')->andReturn(
        new ProviderDescriptor('file.provider', 'File Provider', '1.0.0', '1.0.0'),
    );
    $provider->shouldReceive('health')->once()->andReturn(
        new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable),
    );
    $provider->shouldReceive('capabilities')->andReturn(
        new CapabilitySet([
            new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                new CapabilityChannel(CapabilityDelivery::FileExchange, BootstrapsWorkforce::class),
            ]),
        ]),
    );
    $provider->shouldReceive('resolvePort')->once()->with(BootstrapsWorkforce::class)->andReturn($bootstrap);
    $bootstrap->shouldReceive('bootstrap')->once()->andReturn(
        new WorkforcePage([], new DateTimeImmutable, resumeCursor: 'file-checksum', complete: true),
    );

    expect(ProviderConformance::violations($provider))->toBe([]);
});

test('workforce records enforce exhaustive reference types', function (): void {
    $when = new DateTimeImmutable('2026-08-31T00:00:00+00:00');
    $companyRef = new ExternalReference('test.provider', WorkforceResourceType::Company, 'COMPANY-1');
    $organizationRef = new ExternalReference('test.provider', WorkforceResourceType::OrganizationUnit, 'DEPT-1');
    $positionRef = new ExternalReference('test.provider', WorkforceResourceType::Position, 'POSITION-1');
    $employeeRef = new ExternalReference('test.provider', WorkforceResourceType::Employee, 'EMPLOYEE-1');

    $changes = [
        new WorkforceUpsert(new WorkforceCompany($companyRef, 'Company', true, $when), $when),
        new WorkforceUpsert(new WorkforceOrganizationUnit($organizationRef, $companyRef, 'Engineering', true, $when, $when), $when),
        new WorkforceUpsert(new WorkforcePosition($positionRef, $companyRef, 'Engineer', true, $when, $when), $when),
        new WorkforceUpsert(new WorkforceEmployee($employeeRef, $companyRef, 'Employee', true, $when, $when), $when),
        new WorkforceDeactivation($positionRef, $when),
        new WorkforceMerge(
            $employeeRef,
            new ExternalReference('test.provider', WorkforceResourceType::Employee, 'EMPLOYEE-2'),
            $when,
        ),
    ];

    $page = new WorkforceChangePage($changes, $when, resumeCursor: 'checkpoint-2', complete: true);

    expect($page->changes)->toHaveCount(6)
        ->and($page->changes[0]->record)->toBeInstanceOf(WorkforceCompany::class)
        ->and($page->changes[3]->record)->toBeInstanceOf(WorkforceEmployee::class);

    expect(fn () => new WorkforceCompany($employeeRef, 'Wrong', true, $when))
        ->toThrow(InvalidArgumentException::class, 'company reference');
});

test('provider file inspection states are unambiguous', function (): void {
    $hash = str_repeat('a', 64);
    $file = new ProviderFile('employees.csv', $hash, '/imports/employees.csv');
    $accepted = new ProviderFileInspection(true, $hash, 'hr2000-export-v1');
    $rejected = new ProviderFileInspection(false, $hash, 'hr2000-export-v1', ['Missing employee number.']);

    expect($accepted->accepted)->toBeTrue()
        ->and($rejected->accepted)->toBeFalse();

    expect(fn () => new ProviderFileInspection(true, $hash, 'hr2000-export-v1', ['Contradiction']))
        ->toThrow(InvalidArgumentException::class, 'cannot have errors');
    expect(fn () => new ProviderFileInspection(false, $hash, 'hr2000-export-v1'))
        ->toThrow(InvalidArgumentException::class, 'require an explanation');

    expect(new ProviderFileImportResult($file, $accepted, 1, 0)->accepted)->toBe(1);

    $changedFile = new ProviderFile('employees.csv', str_repeat('b', 64), '/imports/employees.csv');
    expect(fn () => new ProviderFileImportResult($changedFile, $accepted, 1, 0))
        ->toThrow(InvalidArgumentException::class, 'exact file hash');
    expect(fn () => new ProviderFileImportResult($file, $rejected, 0, 1, [
        ['row' => 1, 'code' => 'invalid', 'detail' => 'Rejected.'],
    ]))->toThrow(InvalidArgumentException::class, 'accepted inspection');
});
