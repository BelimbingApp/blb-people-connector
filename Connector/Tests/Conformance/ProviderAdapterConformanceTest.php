<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\PeopleConnector\Connector\Contracts\AuthenticatesProvider;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\Hr2000DeploymentProfile;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderPortResolver;
use App\Domains\PeopleConnector\Connector\Support\WorkforcePageChecksum;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const PROVIDER_CONFORMANCE_CASES = [
    'bootstrap_paging',
    'change_feed',
    'cursor_round_trip',
    'refusal_semantics',
    'page_checksum',
];

/**
 * Every adapter has one explicit disposition for every shared case. A future
 * adapter joins the suite by adding one row; silently deleting a case from a
 * row is itself a test failure.
 */
dataset('provider adapter conformance registrations', providerAdapterConformanceRegistrations());

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

test('every shipped adapter is registered in the shared conformance dataset', function (): void {
    $ids = array_map(static fn (array $row): string => $row[0]['id'], providerAdapterConformanceRegistrations());

    expect(array_values($ids))->toBe([
        FirstPartyPeopleAdapter::ID,
        Hr2000Adapter::ID,
    ]);
});

test('each adapter registers every shared conformance case', function (array $registration): void {
    expect(array_keys($registration['cases']))->toBe(PROVIDER_CONFORMANCE_CASES)
        ->and($registration['cases']['refusal_semantics'])->toBe('required');

    $feedDisposition = $registration['cases']['bootstrap_paging'];
    expect($feedDisposition)->toBeIn(['supported', 'unsupported']);
    foreach (['change_feed', 'cursor_round_trip', 'page_checksum'] as $case) {
        expect($registration['cases'][$case])->toBe($feedDisposition);
    }
})->with('provider adapter conformance registrations');

test('registered adapters satisfy paging cursor and checksum dispositions', function (array $registration): void {
    $adapter = ($registration['factory'])();

    expect($adapter->descriptor()->id)->toBe($registration['id']);

    $authorization = ProviderPortAuthorization::forConformance($registration['id']);
    $bootstrap = $adapter instanceof ResolvesProviderPorts
        ? $adapter->resolvePort(BootstrapsWorkforce::class, $authorization)
        : null;
    $changes = $adapter instanceof ResolvesProviderPorts
        ? $adapter->resolvePort(ReadsWorkforceChanges::class, $authorization)
        : null;

    if ($registration['cases']['bootstrap_paging'] === 'unsupported') {
        expect($bootstrap)->toBeNull()
            ->and($changes)->toBeNull()
            ->and($adapter->capabilities()->portContracts(PeopleCapability::EmployeeDirectory))->toBe([])
            ->and(ProviderConformance::violations($adapter))->toBe([]);

        return;
    }

    $fixture = conformancePeopleFixture();
    $adapter = ($registration['factory'])();
    $authorization = ProviderPortAuthorization::forConformance($registration['id']);
    $bootstrap = $adapter->resolvePort(BootstrapsWorkforce::class, $authorization);
    $changes = $adapter->resolvePort(ReadsWorkforceChanges::class, $authorization);

    expect($bootstrap)->toBeInstanceOf(BootstrapsWorkforce::class)
        ->and($changes)->toBeInstanceOf(ReadsWorkforceChanges::class)
        ->and(ProviderConformance::violations($adapter))->toBe([]);

    $bootstrapPages = conformanceBootstrapPages($bootstrap);
    $checkpoint = $bootstrapPages[array_key_last($bootstrapPages)]->resumeCursor;

    Carbon::setTestNow('2026-09-06 09:00:00 UTC');
    foreach ($fixture['employees'] as $employee) {
        $employee->update(['short_name' => 'Changed '.$employee->id]);
    }

    $changePages = conformanceChangePages($changes, $checkpoint);

    expect($bootstrapPages)->toHaveCount(2)
        ->and($changePages)->toHaveCount(2)
        ->and($bootstrapPages[0]->nextPageCursor)->not->toBeNull()
        ->and($bootstrapPages[1]->resumeCursor)->not->toBeNull()
        ->and($changePages[0]->nextPageCursor)->not->toBeNull()
        ->and($changePages[1]->resumeCursor)->not->toBeNull();

    foreach ([...$bootstrapPages, ...$changePages] as $page) {
        expect($page->checksum)->not->toBeNull()
            ->and($page->checksum)->toBe(WorkforcePageChecksum::of($page));
    }
})->with('provider adapter conformance registrations');

test('registered adapters preserve distinct unsupported unavailable and unauthorized refusals', function (array $registration): void {
    $adapter = ($registration['factory'])();
    [$actor, $scope] = conformanceProviderAccess($adapter->descriptor()->id);

    $denied = Mockery::mock(AuthorizationService::class);
    $denied->shouldReceive('authorize')->once()->andThrow(new AuthorizationDeniedException(
        AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY),
    ));
    app()->instance(AuthorizationService::class, $denied);

    expect(fn () => app(ProviderPortResolver::class)->read(
        $actor,
        $adapter,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        $scope,
    ))->toThrow(AuthorizationDeniedException::class);

    $allowed = Mockery::mock(AuthorizationService::class);
    $allowed->shouldReceive('authorize')->andReturnNull();
    app()->instance(AuthorizationService::class, $allowed);

    expect(fn () => app(ProviderPortResolver::class)->write(
        $actor,
        $adapter,
        PeopleCapability::EmployeeDirectory,
        AuthenticatesProvider::class,
        $scope,
    ))->toThrow(UnsupportedProviderOperation::class);

    $unavailable = new class($adapter) implements ProviderAdapter, ResolvesProviderPorts
    {
        public function __construct(private readonly ProviderAdapter $adapter) {}

        public function descriptor(): ProviderDescriptor
        {
            return $this->adapter->descriptor();
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
            ])]);
        }

        public function health(): ProviderHealth
        {
            return $this->adapter->health();
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return null;
        }
    };

    expect(fn () => app(ProviderPortResolver::class)->read(
        $actor,
        $unavailable,
        PeopleCapability::EmployeeDirectory,
        BootstrapsWorkforce::class,
        $scope,
    ))->toThrow(ProviderCompatibilityException::class);
})->with('provider adapter conformance registrations');

/** @return array{employees: list<Employee>} */
function conformancePeopleFixture(): array
{
    Carbon::setTestNow('2026-09-06 07:00:00 UTC');
    [$tenant, $company] = createTenantWithCompany(['name' => 'Adapter Conformance']);
    app(TenantContext::class)->set((int) $tenant->id);

    return ['employees' => [
        Employee::factory()->create(['company_id' => $company->id, 'employee_type' => 'full_time']),
        Employee::factory()->create(['company_id' => $company->id, 'employee_type' => 'full_time']),
    ]];
}

/** @return list<object> */
function conformanceBootstrapPages(BootstrapsWorkforce $port): array
{
    Carbon::setTestNow('2026-09-06 08:00:00 UTC');
    $first = $port->bootstrap(new WorkforcePageRequest(limit: 1));
    $second = $port->bootstrap(new WorkforcePageRequest($first->nextPageCursor, 1));

    return [$first, $second];
}

/** @return list<object> */
function conformanceChangePages(ReadsWorkforceChanges $port, string $resumeCursor): array
{
    Carbon::setTestNow('2026-09-06 10:00:00 UTC');
    $first = $port->changes(new WorkforceChangeRequest($resumeCursor, limit: 1));
    $second = $port->changes(new WorkforceChangeRequest($resumeCursor, $first->nextPageCursor, 1));

    return [$first, $second];
}

/** @return array{Actor, ProviderScope} */
function conformanceProviderAccess(string $providerId): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Refusal '.$providerId]);
    app(TenantContext::class)->set((int) $tenant->id);
    $scope = ProviderScope::company((int) $company->id);
    $connection = app(ProviderConnectionStore::class)->configure($scope, $providerId);
    app(ProviderConnectionStore::class)->activate((int) $connection->id);

    return [
        new Actor(PrincipalType::USER, 1009, (int) $company->id, tenantId: (int) $tenant->id),
        $scope,
    ];
}

/** @return array<string, array{array{id: string, factory: Closure, cases: array<string, string>}}> */
function providerAdapterConformanceRegistrations(): array
{
    return [
        'first-party People' => [[
            'id' => FirstPartyPeopleAdapter::ID,
            'factory' => fn (): ProviderAdapter => app(FirstPartyPeopleAdapter::class),
            'cases' => [
                'bootstrap_paging' => 'supported',
                'change_feed' => 'supported',
                'cursor_round_trip' => 'supported',
                'refusal_semantics' => 'required',
                'page_checksum' => 'supported',
            ],
        ]],
        'HR2000 SBG undiscovered profile' => [[
            'id' => Hr2000Adapter::ID,
            'factory' => fn (): ProviderAdapter => new Hr2000Adapter(Hr2000DeploymentProfile::undiscovered()),
            'cases' => [
                'bootstrap_paging' => 'unsupported',
                'change_feed' => 'unsupported',
                'cursor_round_trip' => 'unsupported',
                'refusal_semantics' => 'required',
                'page_checksum' => 'unsupported',
            ],
        ]],
    ];
}
