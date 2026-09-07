<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDefect;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;

/**
 * Parse an HR2000 employee CSV export and report counts and defects, writing
 * nothing (#161). Runs inside one tenant (--tenant, from the base) because an
 * export is one tenant's personnel data, even when nothing is written. Exits
 * non-zero while any defect exists. Prints reason codes only: this output lands
 * in a terminal.
 */
final class Hr2000ImportDryRunCommand extends TenantScopedCommand
{
    protected $signature = 'connector:hr2000:import:dry-run
                            {path : Path to the HR2000 employee CSV export}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Parse an HR2000 employee CSV export into typed workforce records and report defects, without writing';

    public function handle(Hr2000EmployeeCsvParser $parser): int
    {
        $path = (string) $this->argument('path');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            $this->error('file_unreadable: '.basename($path));

            return self::FAILURE;
        }

        $run = $parser->parse(new ProviderFile(basename($path), hash('sha256', $bytes), $path), now()->toDateTimeImmutable(), $bytes);

        if ($this->option('json')) {
            $this->line(json_encode($run->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("HR2000 import dry run: {$run->file->name} (sha256 {$run->file->sha256}), schema {$run->schemaVersion}. Nothing was written.");
            $this->line("Rows read: {$run->rowsRead}. Typed records: ".count($run->records).". Rejected rows: {$run->rejectedRows()}. Defects: ".count($run->defects).'.');
            $this->table(['row', 'code', 'field'], array_map(
                static fn (Hr2000ImportDefect $d): array => [$d->row === null ? 'file' : (string) $d->row, $d->code, $d->field ?? ''],
                $run->defects,
            ));
        }

        return $run->defects === [] ? self::SUCCESS : self::FAILURE;
    }
}
