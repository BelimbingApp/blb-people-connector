<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeDiscovery;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * connector:file-exchange:discover (#297): walk one connection's inbound
 * directory under people-connector.file_exchange.inbound_root and record each
 * new file in the ledger by SHA-256, touching no byte and no projection.
 * Self-contained: every helper is prefixed discovery and lives here; the only
 * outside helper is the platform's createTenantWithCompany().
 */

const DISCOVERY_TABLE = 'people_connector_connector_file_exchange_records';

const DISCOVERY_HEADER = 'EmpNo,Name,CompanyCode,Department,Position,Email,JoinDate,ResignDate,Status,ReportTo';

beforeEach(function (): void {
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

    $root = sys_get_temp_dir().'/blb-discovery-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    config()->set('people-connector.file_exchange.inbound_root', $root);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    config()->set('people-connector.file_exchange.inbound_root', null);
});

function discoveryRoot(): string
{
    return (string) config('people-connector.file_exchange.inbound_root');
}

/** @return array{tenantId: int, connection: ProviderConnection, operator: User, actor: Actor} */
function discoveryTenant(string $name, string $providerId = 'test.file-discovery'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $providerId)->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    return ['tenantId' => (int) $tenant->id, 'connection' => $connection, 'operator' => $operator, 'actor' => Actor::forUser($operator)];
}

/** Write bytes into the connection's inbound directory; returns the absolute path. */
function discoveryDrop(ProviderConnection $connection, string $name, string $bytes): string
{
    $directory = discoveryRoot().'/'.$connection->id;
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($directory.'/'.$name, $bytes);

    return $directory.'/'.$name;
}

/** @return array{status: int, output: string} */
function discoveryCommand(array $f, array $options = []): array
{
    $status = Artisan::call('connector:file-exchange:discover', ['--tenant' => $f['tenantId'], '--as' => $f['operator']->id] + $options);
    // TenantScopedCommand clears the bound tenant when it ends; the test keeps using tenant services.
    app(TenantContext::class)->set($f['tenantId']);

    return ['status' => $status, 'output' => Artisan::output()];
}

function discoveryRows(int $connectionId): Collection
{
    return DB::table(DISCOVERY_TABLE)->where('provider_connection_id', $connectionId)->orderBy('id')->get();
}

/** @return array<string, int> */
function discoveryConnectorCounts(): array
{
    $counts = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (str_starts_with($table, 'people_connector_connector_') && ! in_array($table, [DISCOVERY_TABLE, 'people_connector_connector_operator_audits'], true)) {
            $counts[$table] = DB::table($table)->count();
        }
    }
    ksort($counts);

    return $counts;
}

test('two new files produce two recorded rows with the right sha256, and only the HR2000 header carries a schema version', function (): void {
    $f = discoveryTenant('Discovery Two Files Tenant');
    $hr = DISCOVERY_HEADER."\n1001,Ada,SBG,IT,Engineer,ada@example.test,2020-01-01,,A,\n";
    $other = "employee_id,name\n1001,Ada\n";
    discoveryDrop($f['connection'], 'export-a.csv', $hr);
    discoveryDrop($f['connection'], 'notes.csv', $other);

    $report = app(FileExchangeDiscovery::class)->discover($f['actor'], $f['connection']);
    $rows = discoveryRows((int) $f['connection']->id)->keyBy('file_name');

    expect($report->counts()['recorded'])->toBe(2)
        ->and($rows)->toHaveCount(2)
        ->and($rows['export-a.csv']->sha256)->toBe(hash('sha256', $hr))
        ->and($rows['export-a.csv']->schema_version)->toBe(Hr2000EmployeeCsvParser::SCHEMA_VERSION)
        ->and($rows['export-a.csv']->operation)->toBe('discovered')
        ->and($rows['export-a.csv']->direction)->toBe('import')
        ->and($rows['export-a.csv']->status)->toBe('recorded')
        ->and($rows['notes.csv']->sha256)->toBe(hash('sha256', $other))
        ->and($rows['notes.csv']->schema_version)->toBeNull();
});

test('a second run over the same directory records nothing and reports already_recorded = 2', function (): void {
    $f = discoveryTenant('Discovery Second Run Tenant');
    discoveryDrop($f['connection'], 'one.csv', DISCOVERY_HEADER."\n1,A,C,D,P,a@x.test,2020-01-01,,A,\n");
    discoveryDrop($f['connection'], 'two.csv', "x,y\n1,2\n");
    $discovery = app(FileExchangeDiscovery::class);

    expect($discovery->discover($f['actor'], $f['connection'])->counts()['recorded'])->toBe(2);
    $before = DB::table(DISCOVERY_TABLE)->count();

    $again = $discovery->discover($f['actor'], $f['connection']);

    expect($again->counts()['recorded'])->toBe(0)
        ->and($again->counts()['already_recorded'])->toBe(2)
        ->and(DB::table(DISCOVERY_TABLE)->count())->toBe($before);
});

test('the same bytes under a different name are already_recorded, not a new row', function (): void {
    $f = discoveryTenant('Discovery Rename Tenant');
    $bytes = "x,y\n1,2\n";
    discoveryDrop($f['connection'], 'original.csv', $bytes);
    $discovery = app(FileExchangeDiscovery::class);
    expect($discovery->discover($f['actor'], $f['connection'])->counts()['recorded'])->toBe(1);

    discoveryDrop($f['connection'], 'copy.csv', $bytes);
    $report = $discovery->discover($f['actor'], $f['connection']);

    expect($report->counts()['recorded'])->toBe(0)
        ->and($report->counts()['already_recorded'])->toBe(2)
        ->and(discoveryRows((int) $f['connection']->id))->toHaveCount(1)
        ->and(discoveryRows((int) $f['connection']->id)->first()->file_name)->toBe('original.csv');
});

test('a symlink pointing outside inbound_root is refused with a non-zero exit and no ledger row', function (): void {
    $f = discoveryTenant('Discovery Symlink Tenant');
    $outside = sys_get_temp_dir().'/blb-discovery-outside-'.getmypid().'.csv';
    file_put_contents($outside, "secret,data\n1,2\n");
    mkdir(discoveryRoot().'/'.$f['connection']->id, 0700, true);
    symlink($outside, discoveryRoot().'/'.$f['connection']->id.'/escape.csv');

    $run = discoveryCommand($f, ['--connection' => $f['connection']->id]);

    expect($run['status'])->not->toBe(0)
        ->and($run['output'])->toContain('escape.csv')
        ->and($run['output'])->not->toContain($outside)
        ->and(discoveryRows((int) $f['connection']->id))->toHaveCount(0);
});

test('connection B files are never recorded under connection A, and --all does not walk another tenant', function (): void {
    $a = discoveryTenant('Discovery Tenant A');
    $bConnection = app(ProviderConnectionStore::class);
    $b = $bConnection->activate((int) $bConnection->configure(ProviderScope::company($a['operator']->company_id), 'test.file-discovery-b')->id);
    $other = discoveryTenant('Discovery Tenant Other', 'test.file-discovery-other');
    app(TenantContext::class)->set($a['tenantId']);

    discoveryDrop($a['connection'], 'a.csv', "a,b\n1,2\n");
    discoveryDrop($b, 'b.csv', "b,c\n3,4\n");
    discoveryDrop($other['connection'], 'other.csv', "o,p\n5,6\n");

    $single = discoveryCommand($a, ['--connection' => $a['connection']->id]);
    expect($single['status'])->toBe(0)
        ->and(discoveryRows((int) $a['connection']->id)->pluck('file_name')->all())->toBe(['a.csv'])
        ->and(discoveryRows((int) $b->id))->toHaveCount(0);

    $all = discoveryCommand($a, ['--all' => true]);
    expect($all['status'])->toBe(0)
        ->and(discoveryRows((int) $b->id)->pluck('file_name')->all())->toBe(['b.csv'])
        ->and(discoveryRows((int) $other['connection']->id))->toHaveCount(0)
        ->and(DB::table(DISCOVERY_TABLE)->where('tenant_id', $other['tenantId'])->count())->toBe(0);
});

test('discovery changes no projection, identity or reconciliation table', function (): void {
    $f = discoveryTenant('Discovery Projection Tenant');
    discoveryDrop($f['connection'], 'export.csv', DISCOVERY_HEADER."\n1001,Ada,SBG,IT,Engineer,ada@example.test,2020-01-01,,A,\n");
    $before = discoveryConnectorCounts();

    expect(discoveryCommand($f, ['--connection' => $f['connection']->id])['status'])->toBe(0)
        ->and(discoveryRows((int) $f['connection']->id))->toHaveCount(1)
        ->and(discoveryConnectorCounts())->toBe($before)
        ->and($before)->toHaveKey('people_connector_connector_workforce_entities');
});

test('the audit row carries counts only and neither it nor the --json output contains a path', function (): void {
    $f = discoveryTenant('Discovery Privacy Tenant');
    $path = discoveryDrop($f['connection'], 'payroll.csv', "employee_id,name\n1001,Ada Private\n");

    $run = discoveryCommand($f, ['--connection' => $f['connection']->id, '--json' => true]);
    $audits = OperatorAudit::query()->forTenant($f['tenantId'])->where('operation', OperatorAuditOperation::FileExchangeDiscovered->value)->get();

    expect($run['status'])->toBe(0)
        ->and($audits)->toHaveCount(1);

    $audit = $audits->first();
    // Unescaped slashes: with the default escaping a path reads \/tmp\/x and a not->toContain('/tmp/x') passes for the wrong reason.
    $raw = json_encode([$audit->before_summary, $audit->after_summary, $audit->review_reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $json = json_decode($run['output'], true, flags: JSON_THROW_ON_ERROR);

    expect($audit->connection_id)->toBe((int) $f['connection']->id)
        ->and($audit->after_summary['recorded'])->toBe(1)
        ->and($audit->after_summary['already_recorded'])->toBe(0)
        ->and($raw)->not->toContain($path)
        ->and($raw)->not->toContain(discoveryRoot())
        ->and($raw)->not->toContain('payroll.csv')
        ->and($run['output'])->not->toContain(discoveryRoot())
        ->and($run['output'])->toContain('payroll.csv')
        ->and($json['connections'][0]['files'][0]['sha256'])->toBe(hash('sha256', "employee_id,name\n1001,Ada Private\n"));
});

test('discovery is refused when no inbound root is configured, and a connection of another tenant is not walked', function (): void {
    $f = discoveryTenant('Discovery Disabled Tenant');
    discoveryDrop($f['connection'], 'waiting.csv', "a,b\n");
    $root = discoveryRoot();
    config()->set('people-connector.file_exchange.inbound_root', null);

    expect(fn () => app(FileExchangeDiscovery::class)->discover($f['actor'], $f['connection']))
        ->toThrow(FileExchangeException::class, 'inbound root');

    config()->set('people-connector.file_exchange.inbound_root', $root);
    $other = discoveryTenant('Discovery Foreign Tenant', 'test.file-discovery-foreign');
    app(TenantContext::class)->set($f['tenantId']);

    expect(fn () => app(FileExchangeDiscovery::class)->discover($f['actor'], $other['connection']))
        ->toThrow(FileExchangeException::class, 'outside the current tenant')
        ->and(DB::table(DISCOVERY_TABLE)->count())->toBe(0);
});
