<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDefect;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDryRun;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportRecord;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;

/**
 * Dry-run parser for one candidate HR2000 employee CSV layout (#161).
 *
 * CSV is the only HR2000 route with any public evidence (the Quick Staff
 * literature cited in docs/contracts/hr2000-discovery.md describes CSV report
 * export); the capability register still verifies nothing for hr2000.sbg, so
 * this layout is a candidate, the parser writes nothing and no adapter port is
 * published. A header that is not exactly the candidate layout is refused: the
 * parser never guesses which column holds what.
 *
 * Every defect is a reason code bound to a row and column, never a cell value.
 * Every accepted row carries provenance bound to the file hash and to the
 * SHA-256 of that row's exact bytes.
 */
final class Hr2000EmployeeCsvParser
{
    public const SCHEMA_VERSION = 'hr2000.sbg.employee-csv.candidate-1';

    public const SOURCE = 'hr2000.file-import';

    public const COLUMNS = ['EmpNo', 'Name', 'CompanyCode', 'Department', 'Position', 'Email', 'JoinDate', 'ResignDate', 'Status', 'ReportTo'];

    private const STATUS_ACTIVE = 'A';

    private const STATUS_RESIGNED = 'R';

    public function parse(ProviderFile $file, \DateTimeImmutable $observedAt, ?string $bytes = null): Hr2000ImportDryRun
    {
        $bytes ??= @file_get_contents($file->path);
        if ($bytes === false) {
            return $this->fileLevel($file, 'file_unreadable');
        }
        if (! hash_equals($file->sha256, hash('sha256', $bytes))) {
            return $this->fileLevel($file, 'file_hash_mismatch');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return $this->fileLevel($file, 'encoding_invalid');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        $lines = preg_split('/\r\n|\n|\r/', $bytes) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === [] || str_getcsv($lines[0], ',', '"', '') !== self::COLUMNS) {
            return $this->fileLevel($file, 'column_layout_unknown');
        }

        $records = [];
        $defects = [];
        $seen = [];
        foreach (array_slice($lines, 1, preserve_keys: true) as $index => $line) {
            $row = $index + 1;
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== count(self::COLUMNS)) {
                $defects[] = new Hr2000ImportDefect($row, 'column_count_mismatch');

                continue;
            }
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), array_combine(self::COLUMNS, $cells));
            $rowDefects = $this->rowDefects($row, $cells, $seen);
            if ($rowDefects !== []) {
                array_push($defects, ...$rowDefects);

                continue;
            }
            $seen[$cells['EmpNo']] = true;
            $records[] = new Hr2000ImportRecord($row, $this->employee($cells, $observedAt), new WorkforceProvenance(
                self::SOURCE,
                $file->sha256,
                Hr2000Adapter::ID.':row:'.$row.':'.hash('sha256', $line),
            ));
        }

        return new Hr2000ImportDryRun($file, self::SCHEMA_VERSION, count($lines) - 1, $records, $defects);
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, true>  $seen
     * @return list<Hr2000ImportDefect>
     */
    private function rowDefects(int $row, array $cells, array $seen): array
    {
        $defects = [];
        foreach (['EmpNo' => 'employee_number_missing', 'Name' => 'name_missing', 'CompanyCode' => 'company_code_missing'] as $column => $code) {
            if ($cells[$column] === '') {
                $defects[] = new Hr2000ImportDefect($row, $code, $column);
            }
        }
        if ($cells['EmpNo'] !== '' && isset($seen[$cells['EmpNo']])) {
            $defects[] = new Hr2000ImportDefect($row, 'employee_number_duplicate', 'EmpNo');
        }
        if ($cells['Email'] !== '' && filter_var($cells['Email'], FILTER_VALIDATE_EMAIL) === false) {
            $defects[] = new Hr2000ImportDefect($row, 'email_invalid', 'Email');
        }
        if ($this->date($cells['JoinDate']) === null) {
            $defects[] = new Hr2000ImportDefect($row, 'date_invalid', 'JoinDate');
        }
        if ($cells['ResignDate'] !== '' && $this->date($cells['ResignDate']) === null) {
            $defects[] = new Hr2000ImportDefect($row, 'date_invalid', 'ResignDate');
        }
        if (! in_array($cells['Status'], [self::STATUS_ACTIVE, self::STATUS_RESIGNED], true)) {
            $defects[] = new Hr2000ImportDefect($row, 'status_unknown', 'Status');
        }
        if ($cells['ReportTo'] !== '' && $cells['ReportTo'] === $cells['EmpNo']) {
            $defects[] = new Hr2000ImportDefect($row, 'manager_self_reference', 'ReportTo');
        }

        return $defects;
    }

    /** @param array<string, string> $cells */
    private function employee(array $cells, \DateTimeImmutable $observedAt): WorkforceEmployee
    {
        $reference = fn (WorkforceResourceType $type, string $id): ?ExternalReference => $id === '' ? null : new ExternalReference(Hr2000Adapter::ID, $type, $id);
        $active = $cells['Status'] === self::STATUS_ACTIVE;
        $resignedAt = $cells['ResignDate'] === '' ? null : $this->date($cells['ResignDate']);

        return new WorkforceEmployee(
            reference: new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::Employee, $cells['EmpNo']),
            companyReference: new ExternalReference(Hr2000Adapter::ID, WorkforceResourceType::Company, $cells['CompanyCode']),
            displayName: $cells['Name'],
            active: $active,
            effectiveAt: (! $active && $resignedAt !== null ? $resignedAt : $this->date($cells['JoinDate'])) ?? $observedAt,
            observedAt: $observedAt,
            employeeNumber: $cells['EmpNo'],
            email: $cells['Email'] === '' ? null : $cells['Email'],
            organizationReference: $reference(WorkforceResourceType::OrganizationUnit, $cells['Department']),
            positionReference: $reference(WorkforceResourceType::Position, $cells['Position']),
            managerReference: $reference(WorkforceResourceType::Employee, $cells['ReportTo']),
            sourceVersion: self::SCHEMA_VERSION,
        );
    }

    /** HR2000 dates are day-first; a value that does not round-trip through d/m/Y is not a date. */
    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('d/m/Y') === $value ? $date : null;
    }

    private function fileLevel(ProviderFile $file, string $code): Hr2000ImportDryRun
    {
        return new Hr2000ImportDryRun($file, self::SCHEMA_VERSION, 0, [], [new Hr2000ImportDefect(null, $code)]);
    }
}
