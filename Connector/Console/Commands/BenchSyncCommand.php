<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncBench;
use Illuminate\Console\Command;

/**
 * Operator entry for WorkforceSyncBench (#254). Authorized like
 * connector:doctor: a named operator inside the tenant, checked before the
 * throwaway connection is provisioned.
 */
final class BenchSyncCommand extends Command
{
    protected $signature = 'connector:bench:sync
                            {--tenant= : Tenant to bench in; defaults to the current tenant context}
                            {--as= : Id of the operator this bench runs as}
                            {--employees= : Synthetic employees per run (at least 1)}
                            {--units=1 : Synthetic organisation units (at least 1)}
                            {--runs=3 : Full sync passes to time}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Time the full workforce sync against a synthetic in-memory provider, then tear it down';

    public function handle(TenantContext $tenants, WorkforceSyncBench $bench): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('The sync bench runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        if (($tenantId = $this->option('tenant')) !== null && $tenantId !== '') {
            $tenants->set((int) $tenantId);
        }

        $report = $bench->run(
            Actor::forUser($operator),
            $this->integerOption('employees'),
            $this->integerOption('units'),
            $this->integerOption('runs'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['run', 'wall ms', 'peak memory', 'pages', 'conflicts', 'rows written'],
                array_map(static fn (array $run): array => [
                    $run['run'], $run['wall_ms'], $run['peak_memory_bytes'], $run['pages'], $run['conflicts'],
                    implode(' ', array_map(static fn (string $table, int $rows): string => substr($table, strlen('people_connector_connector_workforce_')).'='.$rows, array_keys($run['rows_written']), $run['rows_written'])),
                ], $report['runs'] ?? []),
            );
            $this->line(sprintf('employees=%d units=%d p50=%s ms p95=%s ms teardown=%s', $report['employees'], $report['units'], $report['p50_ms'] ?? '-', $report['p95_ms'] ?? '-', ($report['teardown']['restored'] ?? false) ? 'restored' : 'not restored'));
            if ($report['failure'] !== null) {
                $this->error('Bench failed: '.$report['failure']);
            }
        }

        return $report['failure'] === null ? self::SUCCESS : self::FAILURE;
    }

    /** A non-numeric or missing option counts as zero, which the bench refuses by name. */
    private function integerOption(string $name): int
    {
        $value = $this->option($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? $value : 0);
    }
}
