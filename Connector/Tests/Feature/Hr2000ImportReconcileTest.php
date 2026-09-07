<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDryRun;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportReconciliation;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;
use App\Domains\PeopleConnector\Connector\Services\Hr2000ImportReconciler;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR2000 import dry-run reconciliation (#298): each typed record is classified
 * against the connection's current employee projections, active projections
 * the file no longer names are listed as missing_from_file, nothing is
 * written, and no field value leaves the process. Self-contained: helpers are
 * prefixed hr2000Reconcile.
 */
const HR2000_RECONCILE_FIXTURE = __DIR__.'/../Fixtures/hr2000-employee-sample.csv';

beforeEach(function (): void {
    hr2000ReconcileAuthz(true);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    foreach (glob(sys_get_temp_dir().'/hr2000-reconcile-*') ?: [] as $file) {
        @unlink($file);
    }
});

function hr2000ReconcileAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private readonly bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException('connector', 'hr2000_reconcile', 'The operator lacks the connection-manage capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/** @return list<string> the fixture's lines, header first, without line endings */
function hr2000ReconcileLines(): array
{
    return preg_split('/\r\n|\n/', rtrim((string) file_get_contents(HR2000_RECONCILE_FIXTURE), "\r\n"));
}

/** @param  list<string>  $lines */
function hr2000ReconcileFile(array $lines): ProviderFile
{
    $bytes = implode("\r\n", $lines)."\r\n";
    $path = tempnam(sys_get_temp_dir(), 'hr2000-reconcile-');
    file_put_contents($path, $bytes);

    return new ProviderFile(basename($path), hash('sha256', $bytes), $path);
}

/** @param  list<string>  $lines */
function hr2000ReconcileParse(array $lines, string $observedAt = '2026-09-07T01:00:00Z'): Hr2000ImportDryRun
{
    return app(Hr2000EmployeeCsvParser::class)->parse(hr2000ReconcileFile($lines), new DateTimeImmutable($observedAt));
}

/**
 * A tenant with one company, an active hr2000.sbg connection scoped to that company, and an operator in it.
 *
 * @return array{tenantId: int, companyId: int, connection: ProviderConnection, operator: User, actor: Actor}
 */
function hr2000ReconcileTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), Hr2000Adapter::ID)->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    return ['tenantId' => (int) $tenant->id, 'companyId' => (int) $company->id, 'connection' => $connection, 'operator' => $operator, 'actor' => Actor::forUser($operator)];
}

/** Projects every accepted record of $run through the store, the way an approved import would. */
function hr2000ReconcileProject(array $tenant, Hr2000ImportDryRun $run): void
{
    app(TenantContext::class)->set($tenant['tenantId']);
    foreach ($run->records as $record) {
        app(WorkforceProjectionStore::class)->upsert((int) $tenant['connection']->id, $record->employee, $record->provenance);
    }
}

function hr2000ReconcileRun(array $tenant, Hr2000ImportDryRun $run): Hr2000ImportReconciliation
{
    app(TenantContext::class)->set($tenant['tenantId']);

    return app(Hr2000ImportReconciler::class)->reconcile($tenant['actor'], $tenant['connection'], $run);
}

/** @return array<string, list<string>> class => EmpNo values */
function hr2000ReconcileEmpNos(Hr2000ImportReconciliation $reconciliation): array
{
    return array_map(static fn (array $entries): array => array_column($entries, 'employee_number'), $reconciliation->toArray()['classes']);
}

/** Row count of every connector-owned table. @return array<string, int> */
function hr2000ReconcileCounts(): array
{
    $counts = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (str_starts_with($table, 'people_connector_connector_')) {
            $counts[$table] = (int) DB::table($table)->count();
        }
    }
    ksort($counts);

    return $counts;
}

test('against an empty connection every accepted row is would_create, and once projected the same file is all unchanged', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile create');
    $run = hr2000ReconcileParse(hr2000ReconcileLines());

    $empty = hr2000ReconcileRun($tenant, $run);
    expect($empty->counts())->toBe(['would_create' => 4, 'would_update' => 0, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 0, 'missing_from_file' => 0])
        ->and(hr2000ReconcileEmpNos($empty)['would_create'])->toBe(['E1001', 'E1002', 'E1003', 'E1004']);

    hr2000ReconcileProject($tenant, $run);

    $projected = hr2000ReconcileRun($tenant, $run);
    expect($projected->counts())->toBe(['would_create' => 0, 'would_update' => 0, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 4, 'missing_from_file' => 0])
        ->and(hr2000ReconcileEmpNos($projected)['unchanged'])->toBe(['E1001', 'E1002', 'E1003', 'E1004']);
});

test('changing one Department cell yields one would_update naming organization_reference and nothing else', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile update');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));

    $lines = hr2000ReconcileLines();
    $lines[1] = 'E1001,Sample Employee One,SBG01,D-FIN,P-CLERK,sample.one@example.test,03/01/2022,,A,E1003';
    $reconciliation = hr2000ReconcileRun($tenant, hr2000ReconcileParse($lines));

    expect($reconciliation->counts())->toBe(['would_create' => 0, 'would_update' => 1, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 3, 'missing_from_file' => 0])
        ->and($reconciliation->toArray()['classes']['would_update'])->toBe([['employee_number' => 'E1001', 'fields' => ['organization_reference']]])
        ->and(hr2000ReconcileEmpNos($reconciliation)['unchanged'])->toBe(['E1002', 'E1003', 'E1004']);
});

test('Status R for an active projection is would_deactivate, the same row once the projection is inactive is unchanged, and Status A for an inactive projection is would_reactivate', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile status');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));

    $lines = hr2000ReconcileLines();
    $lines[3] = 'E1003,"Sample, Employee Three",SBG01,D-MGT,P-MGR,sample.three@example.test,01/02/2015,31/08/2026,R,';
    $resigned = hr2000ReconcileParse($lines, '2026-09-07T02:00:00Z');
    expect(hr2000ReconcileEmpNos(hr2000ReconcileRun($tenant, $resigned)))->toMatchArray(['would_deactivate' => ['E1003'], 'unchanged' => ['E1001', 'E1002', 'E1004']]);

    hr2000ReconcileProject($tenant, $resigned);
    expect(hr2000ReconcileEmpNos(hr2000ReconcileRun($tenant, $resigned)))->toMatchArray(['would_deactivate' => [], 'unchanged' => ['E1001', 'E1002', 'E1003', 'E1004']]);

    $rehired = hr2000ReconcileParse(hr2000ReconcileLines(), '2026-09-07T03:00:00Z');
    $reconciliation = hr2000ReconcileRun($tenant, $rehired);
    expect($reconciliation->counts())->toMatchArray(['would_reactivate' => 1, 'would_update' => 0, 'unchanged' => 3])
        ->and(hr2000ReconcileEmpNos($reconciliation)['would_reactivate'])->toBe(['E1003']);
});

test('an active projection whose EmpNo is absent from the file is missing_from_file; an inactive one is not', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile missing');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));

    // E1003 is active in the sample, E1002 is resigned: drop both.
    $lines = hr2000ReconcileLines();
    unset($lines[2], $lines[3]);
    $reconciliation = hr2000ReconcileRun($tenant, hr2000ReconcileParse(array_values($lines)));

    expect($reconciliation->counts())->toBe(['would_create' => 0, 'would_update' => 0, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 2, 'missing_from_file' => 1])
        ->and(hr2000ReconcileEmpNos($reconciliation)['missing_from_file'])->toBe(['E1003']);
});

test('projections of a sibling connection in the same tenant and of another tenant are neither compared nor listed as missing', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile isolation');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));

    // A sibling connection in the same tenant (tenant scope, same provider) already projects E1004 under another department, and an employee the file never names.
    $store = app(ProviderConnectionStore::class);
    $sibling = $store->activate((int) $store->configure(ProviderScope::tenant(), Hr2000Adapter::ID)->id);
    $at = new DateTimeImmutable('2026-09-07T01:00:00Z');
    $company = new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::Company, 'SBG01');
    $projections = app(WorkforceProjectionStore::class);
    foreach (['E1004' => 'D-OTHER', 'E7777' => 'D-OPS'] as $empNo => $department) {
        $projections->upsert((int) $sibling->id, new WorkforceEmployee(
            new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::Employee, $empNo), $company, "Sibling {$empNo}", true, $at, $at,
            employeeNumber: $empNo, organizationReference: new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::OrganizationUnit, $department),
        ));
    }

    // Another tenant projects E1001 under another department and an employee the file never names.
    $other = hr2000ReconcileTenant('HR2000 reconcile other tenant');
    foreach (['E1001' => 'D-OTHER', 'E8888' => 'D-OPS'] as $empNo => $department) {
        $projections->upsert((int) $other['connection']->id, new WorkforceEmployee(
            new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::Employee, $empNo), $company, "Other {$empNo}", true, $at, $at,
            employeeNumber: $empNo, organizationReference: new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::OrganizationUnit, $department),
        ));
    }

    $reconciliation = hr2000ReconcileRun($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));
    expect($reconciliation->counts())->toBe(['would_create' => 0, 'would_update' => 0, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 4, 'missing_from_file' => 0]);

    // The other tenant's connection is not addressable from this tenant at all.
    app(TenantContext::class)->set($tenant['tenantId']);
    expect(fn () => app(Hr2000ImportReconciler::class)->reconcile($tenant['actor'], $other['connection'], hr2000ReconcileParse(hr2000ReconcileLines())))
        ->toThrow(ProviderAuthorizationException::class);
});

test('rows with defects are excluded from classification and still reported, and the file still exits non-zero', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile defects');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));

    $lines = hr2000ReconcileLines();
    $lines[1] = 'E1001,Sample Employee One,SBG01,D-FIN,P-CLERK,not-an-email,03/01/2022,,A,E1003';
    $file = hr2000ReconcileFile($lines);

    $run = app(Hr2000EmployeeCsvParser::class)->parse($file, new DateTimeImmutable('2026-09-07T01:00:00Z'));
    $reconciliation = hr2000ReconcileRun($tenant, $run);
    expect($run->rejectedRows())->toBe(1)
        ->and(array_sum($reconciliation->counts()))->toBe(3 + 1)
        ->and(hr2000ReconcileEmpNos($reconciliation))->toMatchArray(['would_update' => [], 'unchanged' => ['E1002', 'E1003', 'E1004'], 'missing_from_file' => ['E1001']]);

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $tenant['connection']->id, '--as' => $tenant['operator']->id]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Rejected rows: 1', 'email_invalid', 'unchanged: 3', 'missing_from_file: 1')
        ->and($output)->not->toContain('not-an-email');
});

test('the command reconciles inside the tenant as a named operator, prints counts and EmpNo tables only, carries them in --json, and writes nothing', function (): void {
    $tenant = hr2000ReconcileTenant('HR2000 reconcile command');
    hr2000ReconcileProject($tenant, hr2000ReconcileParse(hr2000ReconcileLines()));
    $lines = hr2000ReconcileLines();
    $lines[1] = 'E1001,Sample Employee One,SBG01,D-FIN,P-CLERK,sample.one@example.test,03/01/2022,,A,E1003';
    unset($lines[3]);
    $lines[] = 'E1005,Sample Employee Five,SBG01,D-OPS,P-CLERK,sample.five@example.test,01/09/2026,,A,';
    $file = hr2000ReconcileFile(array_values($lines));
    $connectionId = (int) $tenant['connection']->id;
    $operatorId = (int) $tenant['operator']->id;
    $before = hr2000ReconcileCounts();
    expect($before['people_connector_connector_workforce_employees'])->toBe(4);

    // --reconcile needs a connection and an operator; refusals print no classification.
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--as' => $operatorId]))->toBe(1)
        ->and(Artisan::output())->not->toContain('would_create');
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $connectionId]))->toBe(1)
        ->and(Artisan::output())->not->toContain('would_create');
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $connectionId + 1000, '--as' => $operatorId]))->toBe(1)
        ->and(Artisan::output())->not->toContain('would_create');
    hr2000ReconcileAuthz(false);
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $connectionId, '--as' => $operatorId]))->toBe(1)
        ->and(Artisan::output())->not->toContain('would_create');
    hr2000ReconcileAuthz(true);

    // Without --reconcile the report is unchanged from #161.
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId']]))->toBe(0);
    expect(Artisan::output())->toContain('Nothing was written', 'Typed records: 4')
        ->and(Artisan::output())->not->toContain('would_create');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $connectionId, '--as' => $operatorId]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain('Sample')
        ->and($output)->not->toContain('example.test')
        ->and($output)->not->toContain('D-FIN')
        ->and($output)->toContain("Reconciled against connection {$connectionId}", 'would_create: 1', 'would_update: 1', 'would_deactivate: 0', 'would_reactivate: 0', 'unchanged: 2', 'missing_from_file: 1', 'E1005', 'organization_reference');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $file->path, '--tenant' => $tenant['tenantId'], '--reconcile' => true, '--connection' => $connectionId, '--as' => $operatorId, '--json' => true]))->toBe(0);
    $json = trim(Artisan::output());
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('Sample')
        ->and($json)->not->toContain('example.test')
        ->and($json)->not->toContain('D-FIN');
    expect($report['written'])->toBe(0)
        ->and($report['reconciliation']['connection'])->toBe($connectionId)
        ->and($report['reconciliation']['counts'])->toBe(['would_create' => 1, 'would_update' => 1, 'would_deactivate' => 0, 'would_reactivate' => 0, 'unchanged' => 2, 'missing_from_file' => 1])
        ->and($report['reconciliation']['classes']['would_create'])->toBe([['employee_number' => 'E1005']])
        ->and($report['reconciliation']['classes']['would_update'])->toBe([['employee_number' => 'E1001', 'fields' => ['organization_reference']]])
        ->and($report['reconciliation']['classes']['missing_from_file'])->toBe([['employee_number' => 'E1003']]);

    expect(hr2000ReconcileCounts())->toBe($before);
});
