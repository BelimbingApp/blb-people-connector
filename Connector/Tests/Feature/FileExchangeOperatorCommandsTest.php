<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeLedger;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeOperator;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * connector:file-exchange:list / :quarantine / :archive (#285). Self-contained:
 * helpers are prefixed fxOp; the only outside helper is createTenantWithCompany().
 */

const FX_OP_TABLE = 'people_connector_connector_file_exchange_records';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function fxOpAuthz(bool $allow = true): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException(
                    'connector',
                    'file_exchange',
                    'The actor lacks the connector file-exchange capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/** @return array{tenantId: int, companyId: int, connectionId: int, operator: User, actor: Actor} */
function fxOpFixture(string $name, string $providerId = 'test.file-exchange-op'): array
{
    fxOpAuthz();
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $providerId)->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'connectionId' => (int) $connection->id,
        'operator' => $operator,
        'actor' => Actor::forUser($operator),
    ];
}

function fxOpFile(string $name, string $bytes): ProviderFile
{
    $directory = sys_get_temp_dir().'/blb-fx-op-'.getmypid();
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory.'/'.$name;
    file_put_contents($path, $bytes);

    return new ProviderFile($name, hash('sha256', $bytes), $path);
}

function fxOpRecord(array $f, string $name, string $bytes, ?string $schema = 'hr2000-sbg-1'): FileExchangeRecord
{
    app(TenantContext::class)->set($f['tenantId']);
    $connection = app(ProviderConnectionStore::class)->find($f['connectionId']);

    return app(FileExchangeLedger::class)->record(
        $connection,
        fxOpFile($name, $bytes),
        FileExchangeRecord::DIRECTION_IMPORT,
        'workforce.import',
        $f['actor'],
        schemaVersion: $schema,
    );
}

/** @return array{status: int, output: string} */
function fxOpCall(string $command, array $f, array $options = []): array
{
    $status = Artisan::call($command, ['--tenant' => $f['tenantId'], '--as' => $f['operator']->id] + $options);
    app(TenantContext::class)->set($f['tenantId']);

    return ['status' => $status, 'output' => Artisan::output()];
}

test('list shows only the operator tenant and named connection; status filter and json shape hold', function (): void {
    $mine = fxOpFixture('FX Op List Mine', 'test.fx-op-list-mine');
    $sibling = fxOpFixture('FX Op List Sibling', 'test.fx-op-list-sibling');

    $mineRecord = fxOpRecord($mine, 'mine.csv', "a,b\n");
    $quarantined = fxOpRecord($mine, 'bad.csv', "c,d\n");
    app(FileExchangeLedger::class)->quarantine($quarantined, 'schema refused', $mine['actor']);
    fxOpRecord($sibling, 'other-tenant.csv', "e,f\n");

    $list = fxOpCall('connector:file-exchange:list', $mine, ['--connection' => $mine['connectionId']]);
    expect($list['status'])->toBe(0)
        ->and($list['output'])->toContain('mine.csv', 'bad.csv')
        ->and($list['output'])->not->toContain('other-tenant.csv');

    $filtered = fxOpCall('connector:file-exchange:list', $mine, [
        '--connection' => $mine['connectionId'],
        '--status' => 'quarantined',
        '--json' => true,
    ]);
    expect($filtered['status'])->toBe(0);
    $payload = json_decode(trim($filtered['output']), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['file_name'])->toBe('bad.csv')
        ->and($payload['rows'][0]['status'])->toBe('quarantined')
        ->and($payload['rows'][0]['sha256_prefix'])->toBe(substr(hash('sha256', "c,d\n"), 0, 12))
        ->and($payload['rows'][0]['id'])->toBe((int) $quarantined->id)
        ->and($payload['rows'][0])->not->toHaveKey('path')
        ->and(json_encode($payload))->not->toContain(sys_get_temp_dir());

    expect($mineRecord->status)->toBe('recorded');
});

test('quarantine sets status and reason with exactly one audit row; foreign and archived records are refused', function (): void {
    $mine = fxOpFixture('FX Op Quarantine Mine', 'test.fx-op-q-mine');
    $sibling = fxOpFixture('FX Op Quarantine Sibling', 'test.fx-op-q-sibling');
    $record = fxOpRecord($mine, 'to-quarantine.csv', "q,1\n");
    $foreign = fxOpRecord($sibling, 'foreign.csv', "q,2\n");
    $archived = fxOpRecord($mine, 'already-archived.csv', "q,3\n");
    app(FileExchangeLedger::class)->archive($archived, $mine['actor']);

    $auditsBefore = OperatorAudit::query()->count();
    $rowsBefore = DB::table(FX_OP_TABLE)->count();

    $ok = fxOpCall('connector:file-exchange:quarantine', $mine, [
        'record' => $record->id,
        '--reason' => 'schema version not approved',
    ]);
    expect($ok['status'])->toBe(0)
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status'))->toBe('quarantined')
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status_reason'))->toBe('schema version not approved')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore + 1);

    $audit = OperatorAudit::query()->orderByDesc('id')->first();
    expect($audit->operation)->toBe(OperatorAuditOperation::FileExchangeQuarantined)
        ->and($audit->actor_id)->toBe((int) $mine['operator']->id)
        ->and($audit->connection_id)->toBe($mine['connectionId']);

    $foreignAttempt = fxOpCall('connector:file-exchange:quarantine', $mine, [
        'record' => $foreign->id,
        '--reason' => 'should not cross tenants',
    ]);
    expect($foreignAttempt['status'])->toBe(1)
        ->and(DB::table(FX_OP_TABLE)->where('id', $foreign->id)->value('status'))->toBe('recorded')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore + 1);

    $archivedAttempt = fxOpCall('connector:file-exchange:quarantine', $mine, [
        'record' => $archived->id,
        '--reason' => 'too late',
    ]);
    expect($archivedAttempt['status'])->toBe(1)
        ->and($archivedAttempt['output'])->toContain('final')
        ->and(DB::table(FX_OP_TABLE)->where('id', $archived->id)->value('status'))->toBe('archived')
        ->and(DB::table(FX_OP_TABLE)->count())->toBe($rowsBefore);
});

test('archive from quarantined succeeds once; a second archive refuses; audit grows by one', function (): void {
    $f = fxOpFixture('FX Op Archive', 'test.fx-op-archive');
    $record = fxOpRecord($f, 'archive-me.csv', "a,1\n");
    app(FileExchangeLedger::class)->quarantine($record, 'hold for review', $f['actor']);
    $auditsBefore = OperatorAudit::query()->count();

    $first = fxOpCall('connector:file-exchange:archive', $f, ['record' => $record->id]);
    expect($first['status'])->toBe(0)
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status'))->toBe('archived')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore + 1)
        ->and(OperatorAudit::query()->orderByDesc('id')->first()->operation)->toBe(OperatorAuditOperation::FileExchangeArchived);

    $second = fxOpCall('connector:file-exchange:archive', $f, ['record' => $record->id]);
    expect($second['status'])->toBe(1)
        ->and($second['output'])->toContain('final')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore + 1);
});

test('an operator without the capability is refused before any write', function (): void {
    $f = fxOpFixture('FX Op Authz', 'test.fx-op-authz');
    $record = fxOpRecord($f, 'authz.csv', "z,1\n");
    $auditsBefore = OperatorAudit::query()->count();
    $statusBefore = DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status');

    fxOpAuthz(false);

    expect(fxOpCall('connector:file-exchange:list', $f, ['--connection' => $f['connectionId']])['status'])->toBe(1);
    expect(fxOpCall('connector:file-exchange:quarantine', $f, [
        'record' => $record->id,
        '--reason' => 'no capability',
    ])['status'])->toBe(1);
    expect(fxOpCall('connector:file-exchange:archive', $f, ['record' => $record->id])['status'])->toBe(1);

    expect(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status'))->toBe($statusBefore)
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('a reason longer than 190 characters is refused before any write', function (): void {
    $f = fxOpFixture('FX Op Reason Bound', 'test.fx-op-reason');
    $record = fxOpRecord($f, 'long-reason.csv', "r,1\n");
    $auditsBefore = OperatorAudit::query()->count();

    $result = fxOpCall('connector:file-exchange:quarantine', $f, [
        'record' => $record->id,
        '--reason' => str_repeat('r', OperatorAuditLog::MAX_STRING + 1),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain((string) OperatorAuditLog::MAX_STRING)
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status'))->toBe('recorded')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('an empty --reason is refused before any write', function (): void {
    $f = fxOpFixture('FX Op Empty Reason', 'test.fx-op-empty-reason');
    $record = fxOpRecord($f, 'no-reason.csv', "n,1\n");
    $auditsBefore = OperatorAudit::query()->count();

    $result = fxOpCall('connector:file-exchange:quarantine', $f, [
        'record' => $record->id,
        '--reason' => '   ',
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('Pass --reason=')
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status'))->toBe('recorded')
        ->and(DB::table(FX_OP_TABLE)->where('id', $record->id)->value('status_reason'))->toBeNull()
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('each command refuses to run without a tenant scope', function (string $command, array $extra): void {
    $f = fxOpFixture('FX Op No Tenant', 'test.fx-op-no-tenant');
    $record = fxOpRecord($f, 'need-tenant.csv', "t,1\n");
    app(TenantContext::class)->clear();

    $args = ['--as' => $f['operator']->id] + $extra;
    if ($command !== 'connector:file-exchange:list') {
        $args['record'] = $record->id;
    }

    expect(Artisan::call($command, $args))->toBe(1)
        ->and(Artisan::output())->toContain('A --tenant=<id> option is required before this command can run.');
})->with([
    'list' => ['connector:file-exchange:list', ['--connection' => 1]],
    'quarantine' => ['connector:file-exchange:quarantine', ['--reason' => 'x']],
    'archive' => ['connector:file-exchange:archive', []],
]);

test('list resolves the connection through the store so a foreign connection id is refused', function (): void {
    $mine = fxOpFixture('FX Op Conn Mine', 'test.fx-op-conn-mine');
    $sibling = fxOpFixture('FX Op Conn Sibling', 'test.fx-op-conn-sibling');
    fxOpRecord($sibling, 'hidden.csv', "h,1\n");

    $result = fxOpCall('connector:file-exchange:list', $mine, [
        '--connection' => $sibling['connectionId'],
        '--json' => true,
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('not found');
});

test('FileExchangeOperator capability constants match the issue contract', function (): void {
    expect(FileExchangeOperator::LIST_CAPABILITY)->toBe('people-connector.connection.list')
        ->and(FileExchangeOperator::MANAGE_CAPABILITY)->toBe('people-connector.connection.manage');
});

test('a second connection in the same tenant is not listed under the first', function (): void {
    $f = fxOpFixture('FX Op Two Connections', 'test.fx-op-two-a');
    $store = app(ProviderConnectionStore::class);
    $other = $store->activate((int) $store->configure(ProviderScope::company($f['companyId']), 'test.fx-op-two-b')->id);
    $second = [
        'tenantId' => $f['tenantId'],
        'companyId' => $f['companyId'],
        'connectionId' => (int) $other->id,
        'operator' => $f['operator'],
        'actor' => $f['actor'],
    ];

    fxOpRecord($f, 'first-connection.csv', "a,1\n");
    fxOpRecord($second, 'second-connection.csv', "b,2\n");

    $list = fxOpCall('connector:file-exchange:list', $f, ['--connection' => $f['connectionId']]);

    expect($list['status'])->toBe(0)
        ->and($list['output'])->toContain('first-connection.csv')
        ->and($list['output'])->not->toContain('second-connection.csv');
});

test('--since leaves out rows recorded before it', function (): void {
    $f = fxOpFixture('FX Op Since', 'test.fx-op-since');
    Carbon::setTestNow('2026-09-01T09:00:00+00:00');
    fxOpRecord($f, 'old-row.csv', "old,1\n");
    Carbon::setTestNow('2026-09-05T09:00:00+00:00');
    fxOpRecord($f, 'new-row.csv', "new,1\n");
    Carbon::setTestNow();

    $list = fxOpCall('connector:file-exchange:list', $f, [
        '--connection' => $f['connectionId'],
        '--since' => '2026-09-03T00:00:00+00:00',
    ]);

    expect($list['status'])->toBe(0)
        ->and($list['output'])->toContain('new-row.csv')
        ->and($list['output'])->not->toContain('old-row.csv');
});

test('an unknown --status is refused rather than silently matching nothing', function (): void {
    $f = fxOpFixture('FX Op Bad Status', 'test.fx-op-bad-status');
    fxOpRecord($f, 'present.csv', "p,1\n");

    $list = fxOpCall('connector:file-exchange:list', $f, [
        '--connection' => $f['connectionId'],
        '--status' => 'shredded',
    ]);

    expect($list['status'])->toBe(1)
        ->and($list['output'])->toContain('recorded, quarantined or archived');
});
