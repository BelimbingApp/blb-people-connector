<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeLedger;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed fileExchange and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany().
 *
 * The ledger (#263) is the record every file passes through before a parser
 * sees it: bytes hashed by the ledger itself, one row per connection,
 * direction and hash, tenant-bound, immutable except for status, and one
 * audit row per write that never carries the path.
 */

const FILE_EXCHANGE_TABLE = 'people_connector_connector_file_exchange_records';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** A real file on disk under the test's scratch directory, with its true lowercase SHA-256. */
function fileExchangeFile(string $name, string $bytes, ?string $declaredSha256 = null): ProviderFile
{
    $directory = sys_get_temp_dir().'/blb-file-exchange-'.getmypid();

    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $path = $directory.'/'.$name;
    file_put_contents($path, $bytes);

    return new ProviderFile($name, $declaredSha256 ?? hash('sha256', $bytes), $path);
}

/** @return array{tenantId: int, companyId: int, connection: ProviderConnection, actor: Actor} */
function fileExchangeTenant(string $name, string $providerId = 'test.file-exchange'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), $providerId);
    $connection = $store->activate((int) $connection->id);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'connection' => $connection,
        'actor' => new Actor(PrincipalType::USER, 7001, (int) $company->id, tenantId: $tenantId),
    ];
}

function fileExchangeRows(): int
{
    return DB::table(FILE_EXCHANGE_TABLE)->count();
}

test('recording a file writes one row whose sha256 is computed from the bytes on disk', function (): void {
    $f = fileExchangeTenant('File Exchange Recording Tenant');
    $bytes = "employee_id,name\n1001,Ada\n";
    $file = fileExchangeFile('payroll-2026-09.csv', $bytes);

    $record = app(FileExchangeLedger::class)->record(
        $f['connection'],
        $file,
        FileExchangeRecord::DIRECTION_IMPORT,
        'workforce.import',
        $f['actor'],
        schemaVersion: 'hr2000-sbg-1',
        evidenceReference: 'evidence-2026-09-07',
    );

    expect(fileExchangeRows())->toBe(1)
        ->and($record->sha256)->toBe(hash('sha256', $bytes))
        ->and($record->byte_length)->toBe(strlen($bytes))
        ->and($record->tenant_id)->toBe($f['tenantId'])
        ->and($record->company_id)->toBe($f['companyId'])
        ->and($record->provider_connection_id)->toBe((int) $f['connection']->id)
        ->and($record->direction)->toBe('import')
        ->and($record->operation)->toBe('workforce.import')
        ->and($record->file_name)->toBe('payroll-2026-09.csv')
        ->and($record->schema_version)->toBe('hr2000-sbg-1')
        ->and($record->evidence_reference)->toBe('evidence-2026-09-07')
        ->and($record->actor_user_id)->toBe(7001)
        ->and($record->status)->toBe('recorded')
        ->and($record->recorded_at)->not->toBeNull();
});

test('a provider file whose declared sha256 differs from its bytes is refused and nothing is written', function (): void {
    $f = fileExchangeTenant('File Exchange Mismatch Tenant');
    $file = fileExchangeFile('tampered.csv', "employee_id,name\n1001,Ada\n", str_repeat('a', 64));

    expect(fn () => app(FileExchangeLedger::class)->record($f['connection'], $file, 'import', 'workforce.import', $f['actor']))
        ->toThrow(FileExchangeException::class, 'not the hash of its bytes');

    expect(fileExchangeRows())->toBe(0)
        ->and(OperatorAudit::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('the same bytes twice under one connection and direction return the first row; the other direction is a second row', function (): void {
    $f = fileExchangeTenant('File Exchange Duplicate Tenant');
    $bytes = "employee_id,name\n1001,Ada\n";
    $ledger = app(FileExchangeLedger::class);

    $first = $ledger->record($f['connection'], fileExchangeFile('first.csv', $bytes), 'import', 'workforce.import', $f['actor']);
    // A different name and operation: the bytes are what the ledger keys on.
    $again = $ledger->record($f['connection'], fileExchangeFile('renamed.csv', $bytes), 'import', 'workforce.reimport', $f['actor']);

    expect($again->id)->toBe($first->id)
        ->and($again->file_name)->toBe('first.csv')
        ->and(fileExchangeRows())->toBe(1)
        // No write, no audit: the duplicate did nothing.
        ->and(OperatorAudit::query()->forTenant($f['tenantId'])->count())->toBe(1);

    $export = $ledger->record($f['connection'], fileExchangeFile('produced.csv', $bytes), 'export', 'workforce.export', $f['actor']);

    expect($export->id)->not->toBe($first->id)
        ->and($export->direction)->toBe('export')
        ->and(fileExchangeRows())->toBe(2);
});

test('the database key refuses a duplicate that bypasses the ledger', function (): void {
    $f = fileExchangeTenant('File Exchange Key Tenant');
    $bytes = "employee_id,name\n1001,Ada\n";
    $first = app(FileExchangeLedger::class)->record($f['connection'], fileExchangeFile('first.csv', $bytes), 'import', 'workforce.import', $f['actor']);

    expect(fn () => DB::table(FILE_EXCHANGE_TABLE)->insert([
        'tenant_id' => $f['tenantId'],
        'provider_connection_id' => $first->provider_connection_id,
        'direction' => 'import',
        'operation' => 'workforce.import',
        'file_name' => 'copy.csv',
        'sha256' => $first->sha256,
        'byte_length' => $first->byte_length,
        'recorded_at' => now(),
        'status' => 'recorded',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a connection owned by another tenant is refused', function (): void {
    $other = fileExchangeTenant('File Exchange Other Tenant', 'test.file-exchange-other');
    $foreignConnection = $other['connection'];
    $mine = fileExchangeTenant('File Exchange Home Tenant');

    // The ambient tenant is now $mine; the connection object belongs to $other.
    expect(fn () => app(FileExchangeLedger::class)->record($foreignConnection, fileExchangeFile('cross.csv', 'x,y'), 'import', 'workforce.import', $mine['actor']))
        ->toThrow(FileExchangeException::class, 'outside the current tenant');

    expect(fileExchangeRows())->toBe(0);
});

test('a retired connection is refused', function (): void {
    $f = fileExchangeTenant('File Exchange Retired Tenant');
    ProviderConnection::query()->whereKey($f['connection']->id)->update(['status' => ProviderConnection::STATUS_RETIRED, 'active_scope_key' => null]);

    expect(fn () => app(FileExchangeLedger::class)->record($f['connection'], fileExchangeFile('late.csv', 'x,y'), 'import', 'workforce.import', $f['actor']))
        ->toThrow(FileExchangeException::class, 'retired');

    expect(fileExchangeRows())->toBe(0);
});

test('the model refuses updates to what it binds and refuses delete; quarantine changes only status and reason', function (): void {
    $f = fileExchangeTenant('File Exchange Immutable Tenant');
    $ledger = app(FileExchangeLedger::class);
    $record = $ledger->record($f['connection'], fileExchangeFile('fixed.csv', 'a,b'), 'import', 'workforce.import', $f['actor']);
    $before = DB::table(FILE_EXCHANGE_TABLE)->where('id', $record->id)->first();

    foreach (['sha256' => str_repeat('b', 64), 'direction' => 'export', 'operation' => 'other', 'provider_connection_id' => 999] as $column => $value) {
        expect(fn () => FileExchangeRecord::query()->findOrFail($record->id)->update([$column => $value]))
            ->toThrow(AppendOnlyRecordException::class, $column);
    }

    expect(fn () => FileExchangeRecord::query()->findOrFail($record->id)->delete())->toThrow(AppendOnlyRecordException::class);

    $quarantined = $ledger->quarantine(FileExchangeRecord::query()->findOrFail($record->id), 'schema version not approved', $f['actor']);
    $after = DB::table(FILE_EXCHANGE_TABLE)->where('id', $record->id)->first();

    expect($quarantined->status)->toBe('quarantined')
        ->and($after->status)->toBe('quarantined')
        ->and($after->status_reason)->toBe('schema version not approved');

    // updated_at is excluded because within one second it may or may not move.
    $changed = array_keys(array_filter((array) $before, fn ($value, $key) => $key !== 'updated_at' && $value !== ((array) $after)[$key], ARRAY_FILTER_USE_BOTH));
    sort($changed);
    expect($changed)->toBe(['status', 'status_reason']);

    // A path or a line break is not a reason.
    expect(fn () => $ledger->quarantine($quarantined, '/var/tmp/secret.csv', $f['actor']))->toThrow(FileExchangeException::class);

    $archived = $ledger->archive($quarantined, $f['actor']);
    expect($archived->status)->toBe('archived')
        ->and(fileExchangeRows())->toBe(1);
});

test('one operator audit row per recorded file, and neither the path nor the contents are in it', function (): void {
    $f = fileExchangeTenant('File Exchange Audit Tenant');
    $bytes = "employee_id,name\n1001,Ada Secretive\n";
    $file = fileExchangeFile('audited.csv', $bytes);
    app(FileExchangeLedger::class)->record($f['connection'], $file, 'import', 'workforce.import', $f['actor'], evidenceReference: 'evidence-audit-1');

    $audits = OperatorAudit::query()->forTenant($f['tenantId'])->get();
    expect($audits)->toHaveCount(1);

    $audit = $audits->first();
    $raw = json_encode([$audit->before_summary, $audit->after_summary, $audit->review_reference], JSON_THROW_ON_ERROR);

    expect($audit->operation)->toBe(OperatorAuditOperation::FileExchangeRecorded)
        ->and($audit->connection_id)->toBe((int) $f['connection']->id)
        ->and($audit->after_summary['sha256'])->toBe(hash('sha256', $bytes))
        ->and($audit->after_summary['file_name'])->toBe('audited.csv')
        ->and($raw)->not->toContain($file->path)
        ->and($raw)->not->toContain(dirname($file->path))
        ->and($raw)->not->toContain('Ada Secretive');
});
