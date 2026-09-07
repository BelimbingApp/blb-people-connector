<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDefect;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportReconciliation;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\Hr2000EmployeeCsvParser;
use App\Domains\PeopleConnector\Connector\Services\Hr2000ImportReconciler;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;

/**
 * Parse an HR2000 employee CSV export and report counts and defects, writing
 * nothing (#161). Runs inside one tenant (--tenant, from the base) because an
 * export is one tenant's personnel data, even when nothing is written. Exits
 * non-zero while any defect exists. Prints reason codes only: this output lands
 * in a terminal.
 *
 * With --reconcile (#298) the typed records are also classified against one
 * connection's current employee projections (--connection, as --as operator):
 * counts per class and a table of EmpNo values, never a field value.
 */
final class Hr2000ImportDryRunCommand extends TenantScopedCommand
{
    protected $signature = 'connector:hr2000:import:dry-run
                            {path : Path to the HR2000 employee CSV export}
                            {--reconcile : Classify each typed record against the connection\'s current projections}
                            {--connection= : Provider connection id to reconcile against (required with --reconcile)}
                            {--as= : Id of the operator the reconciliation runs as (required with --reconcile)}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Parse an HR2000 employee CSV export into typed workforce records and report defects, without writing';

    public function handle(Hr2000EmployeeCsvParser $parser, Hr2000ImportReconciler $reconciler, TenantConnectionLocator $connections): int
    {
        $reconcile = (bool) $this->option('reconcile');
        if ($reconcile) {
            if (! is_numeric($this->option('connection'))) {
                $this->error('Reconciling names the connection: pass --connection=<id>.');

                return self::FAILURE;
            }
            if (($operatorId = $this->option('as')) === null || $operatorId === '') {
                $this->error('Reconciling runs as a named operator: pass --as=<user id>.');

                return self::FAILURE;
            }
            if (($operator = User::query()->find((int) $operatorId)) === null) {
                $this->error("No user [{$operatorId}].");

                return self::FAILURE;
            }
        }

        $path = (string) $this->argument('path');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            $this->error('file_unreadable: '.basename($path));

            return self::FAILURE;
        }

        $run = $parser->parse(new ProviderFile(basename($path), hash('sha256', $bytes), $path), now()->toDateTimeImmutable(), $bytes);

        $reconciliation = null;
        if ($reconcile) {
            try {
                $reconciliation = $reconciler->reconcile(Actor::forUser($operator), $connections->get((int) $this->option('connection')), $run);
            } catch (AuthorizationDeniedException|ProviderAuthorizationException|InvalidProviderConfigurationException|ConnectorRecordNotFoundException $refusal) {
                $this->error($refusal->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $report = $run->toArray() + ($reconciliation === null ? [] : ['reconciliation' => $reconciliation->toArray()]);
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("HR2000 import dry run: {$run->file->name} (sha256 {$run->file->sha256}), schema {$run->schemaVersion}. Nothing was written.");
            $this->line("Rows read: {$run->rowsRead}. Typed records: ".count($run->records).". Rejected rows: {$run->rejectedRows()}. Defects: ".count($run->defects).'.');
            $this->table(['row', 'code', 'field'], array_map(
                static fn (Hr2000ImportDefect $d): array => [$d->row === null ? 'file' : (string) $d->row, $d->code, $d->field ?? ''],
                $run->defects,
            ));
            if ($reconciliation !== null) {
                $this->printReconciliation($reconciliation);
            }
        }

        return $run->defects === [] ? self::SUCCESS : self::FAILURE;
    }

    private function printReconciliation(Hr2000ImportReconciliation $reconciliation): void
    {
        $counts = $reconciliation->counts();
        $this->line("Reconciled against connection {$reconciliation->connectionId}: ".implode(', ', array_map(
            static fn (string $class, int $count): string => "{$class}: {$count}",
            array_keys($counts),
            $counts,
        )).'.');
        foreach ($reconciliation->classes as $class => $entries) {
            if ($entries === []) {
                continue;
            }
            $this->line("{$class} ({$counts[$class]})");
            $this->table(['EmpNo', 'fields'], array_map(
                static fn (array $entry): array => [$entry['employee_number'], implode(', ', $entry['fields'] ?? [])],
                $entries,
            ));
        }
    }
}
