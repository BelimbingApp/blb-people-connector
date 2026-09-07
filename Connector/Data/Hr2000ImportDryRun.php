<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * Result of parsing an HR2000 export without writing anything (#161). The
 * inspection reuses the file-exchange contract: accepted only when no defect
 * exists, because no verified HR2000 schema defines row-level rejection yet.
 */
final readonly class Hr2000ImportDryRun
{
    /**
     * @param  list<Hr2000ImportRecord>  $records
     * @param  list<Hr2000ImportDefect>  $defects
     */
    public function __construct(
        public ProviderFile $file,
        public string $schemaVersion,
        public int $rowsRead,
        public array $records,
        public array $defects,
    ) {
        if ($rowsRead < 0 || count($records) > $rowsRead) {
            throw new \InvalidArgumentException('HR2000 dry runs cannot accept more rows than were read.');
        }
    }

    public function inspection(): ProviderFileInspection
    {
        $codes = array_values(array_unique(array_map(static fn (Hr2000ImportDefect $d): string => $d->code, $this->defects)));

        return new ProviderFileInspection($codes === [], $this->file->sha256, $this->schemaVersion, $codes);
    }

    public function rejectedRows(): int
    {
        $rows = [];
        foreach ($this->defects as $defect) {
            if ($defect->row !== null) {
                $rows[$defect->row] = true;
            }
        }

        return count($rows);
    }

    /** Counts and reason codes only: never row values. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->file->name,
            'sha256' => $this->file->sha256,
            'schema_version' => $this->schemaVersion,
            'accepted_by_inspection' => $this->inspection()->accepted,
            'rows_read' => $this->rowsRead,
            'records' => count($this->records),
            'rejected_rows' => $this->rejectedRows(),
            'defects' => array_map(static fn (Hr2000ImportDefect $d): array => $d->toArray(), $this->defects),
            'written' => 0,
        ];
    }
}
