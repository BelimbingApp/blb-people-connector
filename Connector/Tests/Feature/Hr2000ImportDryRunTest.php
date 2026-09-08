<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDryRun;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportRecord;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR2000 employee CSV dry run (#161): the anonymised sample parses into typed
 * employees with hash-bound provenance, each defect class is a reason code on
 * its row, an unknown header is refused, and the tenant-scoped command writes
 * nothing. Self-contained: helpers are prefixed hr2000DryRun.
 */
const HR2000_DRY_RUN_FIXTURE = __DIR__.'/../Fixtures/hr2000-employee-sample.csv';

afterEach(function (): void {
    app(TenantContext::class)->clear();
    foreach (glob(sys_get_temp_dir().'/hr2000-dry-run-*') ?: [] as $file) {
        @unlink($file);
    }
});

/** @return list<string> the fixture's lines, header first, without line endings (the fixture is CRLF; a checkout that normalised it must not change what a row hashes to) */
function hr2000DryRunLines(): array
{
    return preg_split('/\r\n|\n/', rtrim((string) file_get_contents(HR2000_DRY_RUN_FIXTURE), "\r\n"));
}

/** Writes $bytes to a temp file and returns it as a ProviderFile whose hash matches the bytes unless $sha256 overrides it. */
function hr2000DryRunFile(string $bytes, ?string $sha256 = null): ProviderFile
{
    $path = tempnam(sys_get_temp_dir(), 'hr2000-dry-run-');
    file_put_contents($path, $bytes);

    return new ProviderFile(basename($path), $sha256 ?? hash('sha256', $bytes), $path);
}

/** Writes unique rows until the file holds at least $bytes bytes, never holding the file in memory; sized and hashed off disk (#301). */
function hr2000DryRunFileOfSize(int $bytes): ProviderFile
{
    $path = tempnam(sys_get_temp_dir(), 'hr2000-dry-run-');
    $handle = fopen($path, 'wb');
    fwrite($handle, implode(',', Hr2000EmployeeCsvParser::COLUMNS)."\r\n");
    for ($i = 0; ftell($handle) < $bytes; $i++) {
        fwrite($handle, sprintf('E%06d,Sample Employee,SBG01,D-OPS,P-CLERK,,03/01/2022,,A,', $i)."\r\n");
    }
    fclose($handle);

    return new ProviderFile(basename($path), (string) hash_file('sha256', $path), $path, (int) filesize($path));
}

function hr2000DryRunParse(ProviderFile $file): Hr2000ImportDryRun
{
    return app(Hr2000EmployeeCsvParser::class)->parse($file, new DateTimeImmutable('2026-09-07T01:00:00Z'));
}

/** @return list<array{row: int|null, code: string, field: string|null}> */
function hr2000DryRunDefects(Hr2000ImportDryRun $run): array
{
    return $run->toArray()['defects'];
}

/** Row count of every connector-owned table, so a write anywhere in the module (ledger included) shows up. @return array<string, int> */
function hr2000DryRunCounts(): array
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

test('the anonymised sample parses into typed employees whose provenance is bound to the file and row hashes', function (): void {
    $bytes = (string) file_get_contents(HR2000_DRY_RUN_FIXTURE);
    $file = new ProviderFile('hr2000-employee-sample.csv', hash('sha256', $bytes), HR2000_DRY_RUN_FIXTURE);

    $run = hr2000DryRunParse($file);

    expect($run->defects)->toBe([])
        ->and($run->rowsRead)->toBe(4)
        ->and($run->inspection()->accepted)->toBeTrue()
        ->and($run->inspection()->sha256)->toBe($file->sha256)
        ->and($run->inspection()->schemaVersion)->toBe(Hr2000EmployeeCsvParser::SCHEMA_VERSION)
        ->and(array_map(static fn (Hr2000ImportRecord $r): int => $r->row, $run->records))->toBe([2, 3, 4, 5]);

    [$one, $two, $three, $four] = $run->records;
    expect($one->employee->reference->providerId)->toBe(Hr2000Adapter::ID)
        ->and($one->employee->reference->resourceType)->toBe(WorkforceResourceType::Employee)
        ->and($one->employee->reference->externalId)->toBe('E1001')
        ->and($one->employee->companyReference->externalId)->toBe('SBG01')
        ->and($one->employee->displayName)->toBe('Sample Employee One')
        ->and($one->employee->email)->toBe('sample.one@example.test')
        ->and($one->employee->active)->toBeTrue()
        ->and($one->employee->effectiveAt->format('Y-m-d'))->toBe('2022-01-03')
        ->and($one->employee->organizationReference?->externalId)->toBe('D-OPS')
        ->and($one->employee->positionReference?->externalId)->toBe('P-CLERK')
        ->and($one->employee->managerReference?->externalId)->toBe('E1003')
        ->and($one->employee->sourceVersion)->toBe(Hr2000EmployeeCsvParser::SCHEMA_VERSION)
        ->and($two->employee->active)->toBeFalse()
        ->and($two->employee->effectiveAt->format('Y-m-d'))->toBe('2024-04-30')
        ->and($two->employee->email)->toBeNull()
        ->and($three->employee->displayName)->toBe('Sample, Employee Three')
        ->and($three->employee->managerReference)->toBeNull()
        ->and($four->employee->organizationReference)->toBeNull()
        ->and($four->employee->effectiveAt->format('Y-m-d'))->toBe('2024-02-29');

    $lines = hr2000DryRunLines();
    expect($one->provenance->source)->toBe(Hr2000EmployeeCsvParser::SOURCE)
        ->and($one->provenance->reviewReference)->toBe($file->sha256)
        ->and($one->provenance->correlationReference)->toBe(Hr2000Adapter::ID.':row:2:'.hash('sha256', $lines[1]))
        ->and($three->provenance->correlationReference)->toBe(Hr2000Adapter::ID.':row:4:'.hash('sha256', $lines[3]));
});

test('a defective row is rejected with its reason code and column, and the other rows still parse', function (int $line, string $replacement, int $row, string $code, ?string $field): void {
    $lines = hr2000DryRunLines();
    $lines[$line] = $replacement;

    $run = hr2000DryRunParse(hr2000DryRunFile(implode("\r\n", $lines)."\r\n"));

    expect(hr2000DryRunDefects($run))->toBe([['row' => $row, 'code' => $code, 'field' => $field]])
        ->and($run->rowsRead)->toBe(4)
        ->and($run->rejectedRows())->toBe(1)
        ->and($run->inspection()->accepted)->toBeFalse()
        ->and($run->inspection()->errors)->toBe([$code])
        ->and(array_map(static fn (Hr2000ImportRecord $r): int => $r->row, $run->records))->toBe(array_values(array_diff([2, 3, 4, 5], [$row])));
})->with([
    'column_count_mismatch' => [1, 'E1001,Sample Employee One,SBG01', 2, 'column_count_mismatch', null],
    'employee_number_missing' => [1, ',Sample Employee One,SBG01,D-OPS,P-CLERK,,03/01/2022,,A,E1003', 2, 'employee_number_missing', 'EmpNo'],
    'name_missing' => [1, 'E1001,,SBG01,D-OPS,P-CLERK,,03/01/2022,,A,E1003', 2, 'name_missing', 'Name'],
    'company_code_missing' => [1, 'E1001,Sample Employee One,,D-OPS,P-CLERK,,03/01/2022,,A,E1003', 2, 'company_code_missing', 'CompanyCode'],
    'employee_number_duplicate' => [4, 'E1001,Sample Employee Four,SBG02,,,,29/02/2024,,A,', 5, 'employee_number_duplicate', 'EmpNo'],
    'email_invalid' => [1, 'E1001,Sample Employee One,SBG01,D-OPS,P-CLERK,not-an-email,03/01/2022,,A,E1003', 2, 'email_invalid', 'Email'],
    'date_invalid (JoinDate does not round-trip)' => [1, 'E1001,Sample Employee One,SBG01,D-OPS,P-CLERK,,31/02/2022,,A,E1003', 2, 'date_invalid', 'JoinDate'],
    'date_invalid (ResignDate)' => [2, 'E1002,Sample Employee Two,SBG01,D-OPS,P-TECH,,15/06/2020,2024-04-30,R,E1003', 3, 'date_invalid', 'ResignDate'],
    'status_unknown' => [1, 'E1001,Sample Employee One,SBG01,D-OPS,P-CLERK,,03/01/2022,,X,E1003', 2, 'status_unknown', 'Status'],
    'manager_self_reference' => [1, 'E1001,Sample Employee One,SBG01,D-OPS,P-CLERK,,03/01/2022,,A,E1001', 2, 'manager_self_reference', 'ReportTo'],
]);

test('a file-level defect refuses the whole file: no record is typed and no row is guessed', function (callable $file, string $code): void {
    $run = hr2000DryRunParse($file());

    expect(hr2000DryRunDefects($run))->toBe([['row' => null, 'code' => $code, 'field' => null]])
        ->and($run->records)->toBe([])
        ->and($run->rowsRead)->toBe(0)
        ->and($run->inspection()->accepted)->toBeFalse();
})->with([
    'column_layout_unknown (renamed column)' => [fn () => hr2000DryRunFile(str_replace('EmpNo,Name', 'Name,EmpNo', (string) file_get_contents(HR2000_DRY_RUN_FIXTURE))), 'column_layout_unknown'],
    'column_layout_unknown (extra column)' => [fn () => hr2000DryRunFile(str_replace('ReportTo', 'ReportTo,Salary', (string) file_get_contents(HR2000_DRY_RUN_FIXTURE))), 'column_layout_unknown'],
    'column_layout_unknown (empty file)' => [fn () => hr2000DryRunFile(''), 'column_layout_unknown'],
    'file_hash_mismatch' => [fn () => hr2000DryRunFile((string) file_get_contents(HR2000_DRY_RUN_FIXTURE), hash('sha256', 'other bytes')), 'file_hash_mismatch'],
    'encoding_invalid' => [fn () => hr2000DryRunFile(str_replace('Sample Employee One', "Sample Employ\xE9e One", (string) file_get_contents(HR2000_DRY_RUN_FIXTURE))), 'encoding_invalid'],
    'file_unreadable' => [fn () => new ProviderFile('missing.csv', hash('sha256', ''), sys_get_temp_dir().'/hr2000-dry-run-missing.csv'), 'file_unreadable'],
]);

test('the dry-run command runs inside one tenant, prints counts and reason codes, exits on defects, and writes nothing', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'HR2000 dry run']);
    $tenantId = (int) $tenant->id;
    $before = hr2000DryRunCounts();
    expect(count($before))->toBeGreaterThan(10)->and($before)->toHaveKey('people_connector_connector_file_exchange_records');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => HR2000_DRY_RUN_FIXTURE]))->toBe(1)
        ->and(Artisan::output())->not->toContain('Rows read');
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => HR2000_DRY_RUN_FIXTURE, '--tenant' => $tenantId + 1000]))->toBe(1)
        ->and(Artisan::output())->not->toContain('Rows read');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => HR2000_DRY_RUN_FIXTURE, '--tenant' => $tenantId]))->toBe(0);
    expect(Artisan::output())->toContain('Nothing was written', 'Rows read: 4', 'Typed records: 4', 'Rejected rows: 0', 'Defects: 0');

    $lines = hr2000DryRunLines();
    $lines[1] = 'E1001,Sample Employee One,SBG01,D-OPS,P-CLERK,secret.address@example.test,31/02/2022,,A,E1003';
    $defective = hr2000DryRunFile(implode("\r\n", $lines)."\r\n");

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $defective->path, '--tenant' => $tenantId]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Typed records: 3', 'Rejected rows: 1', 'date_invalid', 'JoinDate')
        ->and($output)->not->toContain('secret.address')
        ->and($output)->not->toContain('31/02/2022');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $defective->path, '--json' => true, '--tenant' => $tenantId]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['sha256'])->toBe($defective->sha256)
        ->and($report['schema_version'])->toBe(Hr2000EmployeeCsvParser::SCHEMA_VERSION)
        ->and($report['accepted_by_inspection'])->toBeFalse()
        ->and($report['records'])->toBe(3)
        ->and($report['written'])->toBe(0)
        ->and($report['defects'])->toBe([['row' => 2, 'code' => 'date_invalid', 'field' => 'JoinDate']]);

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => sys_get_temp_dir().'/hr2000-dry-run-missing.csv', '--tenant' => $tenantId]))->toBe(1)
        ->and(Artisan::output())->toContain('file_unreadable');

    expect(hr2000DryRunCounts())->toBe($before);
});

test('a file over max_bytes is refused as file_too_large before its bytes are read: no row, no record, and the parse never holds the file', function (): void {
    $maxBytes = 4 * 1024 * 1024;
    config()->set('people-connector.file_exchange.max_bytes', $maxBytes);
    $file = hr2000DryRunFileOfSize($maxBytes + 1);
    expect($file->sizeBytes)->toBeGreaterThan($maxBytes);

    memory_reset_peak_usage();
    $before = memory_get_peak_usage();
    $run = hr2000DryRunParse($file);
    $delta = memory_get_peak_usage() - $before;

    expect(hr2000DryRunDefects($run))->toBe([['row' => null, 'code' => 'file_too_large', 'field' => null]])
        ->and($run->rowsRead)->toBe(0)
        ->and($run->records)->toBe([])
        ->and($run->inspection()->accepted)->toBeFalse()
        ->and($delta)->toBeLessThan($file->sizeBytes);

    // A caller that did not measure the file is bounded by the size on disk, not trusted.
    $unsized = hr2000DryRunParse(new ProviderFile($file->name, $file->sha256, $file->path));
    expect(hr2000DryRunDefects($unsized))->toBe([['row' => null, 'code' => 'file_too_large', 'field' => null]]);
});

test('a file with more than max_rows data rows stops at row max_rows + 1 as row_limit_exceeded, and parses once the limit allows it', function (): void {
    // The sample's four rows plus trailing blank lines: blank lines are not rows.
    $file = hr2000DryRunFile((string) file_get_contents(HR2000_DRY_RUN_FIXTURE)."\r\n\r\n");

    config()->set('people-connector.file_exchange.max_rows', 3);
    $run = hr2000DryRunParse($file);
    expect(hr2000DryRunDefects($run))->toBe([['row' => null, 'code' => 'row_limit_exceeded', 'field' => null]])
        ->and($run->rowsRead)->toBe(4)
        ->and($run->records)->toBe([])
        ->and($run->inspection()->accepted)->toBeFalse();

    config()->set('people-connector.file_exchange.max_rows', 4);
    $run = hr2000DryRunParse($file);
    expect($run->defects)->toBe([])
        ->and($run->rowsRead)->toBe(4)
        ->and(array_map(static fn (Hr2000ImportRecord $r): int => $r->row, $run->records))->toBe([2, 3, 4, 5]);
});

test('the dry-run command streams the digest the ledger keys on and reports an oversized file as a code, never a path or a size', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'HR2000 bounds']);
    $tenantId = (int) $tenant->id;
    $before = hr2000DryRunCounts();

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => HR2000_DRY_RUN_FIXTURE, '--tenant' => $tenantId]))->toBe(0)
        ->and(Artisan::output())->toContain('sha256 '.hash_file('sha256', HR2000_DRY_RUN_FIXTURE));

    config()->set('people-connector.file_exchange.max_bytes', 1024);
    $large = hr2000DryRunFileOfSize(1025);

    // Tenant scope is unchanged: another tenant is refused before the file is opened.
    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $large->path, '--tenant' => $tenantId + 1000]))->toBe(1);
    $refused = Artisan::output();
    expect($refused)->not->toContain('file_too_large')
        ->and($refused)->not->toContain('Rows read');

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $large->path, '--tenant' => $tenantId]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('sha256 '.$large->sha256, 'Rows read: 0', 'Typed records: 0', 'Defects: 1', 'file_too_large')
        ->and($output)->not->toContain(dirname($large->path));

    expect(Artisan::call('connector:hr2000:import:dry-run', ['path' => $large->path, '--json' => true, '--tenant' => $tenantId]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($report))->toBe(['file', 'sha256', 'schema_version', 'accepted_by_inspection', 'rows_read', 'records', 'rejected_rows', 'defects', 'written'])
        ->and($report['file'])->toBe($large->name)
        ->and($report['sha256'])->toBe($large->sha256)
        ->and($report['rows_read'])->toBe(0)
        ->and($report['records'])->toBe(0)
        ->and($report['defects'])->toBe([['row' => null, 'code' => 'file_too_large', 'field' => null]])
        ->and(in_array($large->sizeBytes, $report, true))->toBeFalse();

    expect(hr2000DryRunCounts())->toBe($before);
});
