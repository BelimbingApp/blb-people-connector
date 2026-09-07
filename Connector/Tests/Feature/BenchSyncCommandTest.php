<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncBench;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed benchSync and lives here. The only
 * outside helper is the platform's createTenantWithCompany().
 */

afterEach(fn () => app(TenantContext::class)->clear());

/** @return array{int, User} */
function benchSyncTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return [(int) $tenant->id, User::factory()->create(['company_id' => $company->id])];
}

function benchSyncAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new AuthorizationDeniedException(AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY));
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/** Whole-table row counts for every connector-owned table, keyed by table. @return array<string, int> */
function benchSyncCounts(): array
{
    $counts = [];
    foreach (DomainModels::all() as $model) {
        $table = (new $model)->getTable();
        $counts[$table] = DB::table($table)->count();
    }
    ksort($counts);

    return $counts;
}

/** @return array<string, mixed> */
function benchSyncRun(int $tenantId, User $operator, array $options = []): array
{
    $exit = Artisan::call('connector:bench:sync', ['--tenant' => $tenantId, '--as' => $operator->id, '--json' => true] + $options);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    $report['exit'] = $exit;

    return $report;
}

test('bench sync reports N employees and M units on the first run and zero new projection rows on the second', function (): void {
    [$tenantId, $operator] = benchSyncTenant('Bench Counts Tenant');
    benchSyncAuthz(true);

    $report = benchSyncRun($tenantId, $operator, ['--employees' => 12, '--units' => 3, '--runs' => 2]);

    expect($report['exit'])->toBe(0)
        ->and($report['employees'])->toBe(12)
        ->and($report['units'])->toBe(3)
        ->and($report['runs'])->toHaveCount(2)
        ->and($report['runs'][0]['rows_written'])->toBe([
            'people_connector_connector_workforce_companies' => 1,
            'people_connector_connector_workforce_organization_units' => 3,
            'people_connector_connector_workforce_positions' => 0,
            'people_connector_connector_workforce_employees' => 12,
        ])
        ->and($report['runs'][1]['rows_written'])->toBe([
            'people_connector_connector_workforce_companies' => 0,
            'people_connector_connector_workforce_organization_units' => 0,
            'people_connector_connector_workforce_positions' => 0,
            'people_connector_connector_workforce_employees' => 0,
        ])
        ->and($report['runs'][0]['wall_ms'])->toBeGreaterThanOrEqual(0)
        ->and($report['runs'][0]['peak_memory_bytes'])->toBeGreaterThan(0)
        ->and($report['p50_ms'])->toBeGreaterThanOrEqual(0)
        ->and($report['p95_ms'])->toBeGreaterThanOrEqual($report['p50_ms'])
        ->and($report['failure'])->toBeNull();
});

test('bench sync tears every connector-owned table back to its pre-bench count and deletes the bench connection', function (): void {
    [$tenantId, $operator] = benchSyncTenant('Bench Teardown Tenant');
    benchSyncAuthz(true);
    $before = benchSyncCounts();
    expect($before)->toHaveKey('people_connector_connector_operator_audits');

    $report = benchSyncRun($tenantId, $operator, ['--employees' => 7, '--units' => 2, '--runs' => 1]);

    expect($report['exit'])->toBe(0)
        ->and($report['teardown']['restored'])->toBeTrue()
        ->and(benchSyncCounts())->toBe($before)
        ->and(ProviderConnection::query()->where('provider_id', WorkforceSyncBench::PROVIDER_ID)->count())->toBe(0)
        ->and(DB::table('base_authz_principal_capabilities')->where('principal_type', 'scheduler')->count())->toBe(0);
});

test('bench sync refuses --employees=0 and an unauthorized operator before provisioning anything', function (): void {
    [$tenantId, $operator] = benchSyncTenant('Bench Refusal Tenant');
    benchSyncAuthz(true);
    $before = benchSyncCounts();

    $zero = benchSyncRun($tenantId, $operator, ['--employees' => 0, '--units' => 1]);
    expect($zero['exit'])->toBe(1)
        ->and($zero['failure'])->toBe('invalid_employees')
        ->and(benchSyncCounts())->toBe($before);

    benchSyncAuthz(false);
    $denied = benchSyncRun($tenantId, $operator, ['--employees' => 5, '--units' => 1]);
    expect($denied['exit'])->toBe(1)
        ->and($denied['failure'])->toBe('unauthorized')
        ->and($denied)->not->toHaveKey('runs')
        ->and(benchSyncCounts())->toBe($before)
        ->and(ProviderConnection::query()->count())->toBe(0);
});

test('bench sync never runs against a configured provider and leaves another tenant untouched', function (): void {
    [$tenantId, $operator] = benchSyncTenant('Bench Isolation Tenant');
    [$otherTenantId, $otherOperator] = benchSyncTenant('Bench Other Tenant');
    benchSyncAuthz(true);

    // The other tenant has a real, active, tenant-scoped connection with one projected company.
    app(TenantContext::class)->set($otherTenantId);
    $store = app(ProviderConnectionStore::class);
    $other = $store->activate((int) $store->configure(ProviderScope::tenant(), 'test.bench-other')->id);
    app(WorkforceProjectionStore::class)->upsert((int) $other->id, new WorkforceCompany(
        new ExternalReference('test.bench-other', WorkforceResourceType::Company, 'OTHER-CO'), 'Other Co', true, new DateTimeImmutable('2026-09-01T00:00:00Z'),
    ));
    app(TenantContext::class)->clear();
    $otherRows = fn (): array => array_map(
        static fn (string $table): array => DB::table($table)->where('tenant_id', $otherTenantId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        array_map(static fn (string $model): string => (new $model)->getTable(), DomainModels::all()),
    );
    $otherBefore = $otherRows();

    $report = benchSyncRun($tenantId, $operator, ['--employees' => 4, '--units' => 1]);
    expect($report['exit'])->toBe(0)
        ->and($otherRows())->toBe($otherBefore)
        ->and(ProviderConnection::query()->where('tenant_id', $otherTenantId)->value('status'))->toBe(ProviderConnection::STATUS_ACTIVE);

    // A tenant whose scope already has a configured provider is refused before provisioning.
    $configured = benchSyncRun($otherTenantId, $otherOperator, ['--employees' => 4, '--units' => 1]);
    expect($configured['exit'])->toBe(1)
        ->and($configured['failure'])->toBe('provider_configured')
        ->and($configured)->not->toHaveKey('runs')
        ->and($otherRows())->toBe($otherBefore);
});

test('bench sync CI smoke of fifty employees stays under a generous bound', function (): void {
    [$tenantId, $operator] = benchSyncTenant('Bench Smoke Tenant');
    benchSyncAuthz(true);

    $report = benchSyncRun($tenantId, $operator, ['--employees' => 50, '--units' => 5, '--runs' => 2]);

    expect($report['exit'])->toBe(0)
        ->and($report['runs'][0]['rows_written']['people_connector_connector_workforce_employees'])->toBe(50)
        ->and($report['p95_ms'])->toBeLessThan(30_000)
        ->and($report['teardown']['restored'])->toBeTrue();
});
