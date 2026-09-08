<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\CorruptWorkforcePageException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Testing\SyntheticWorkforceAdapter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load run of the full workforce sync against a synthetic in-memory provider
 * (#254): a throwaway tenant-scoped connection, N bootstrap passes through the
 * real WorkforceSyncRunner, then every row the bench produced removed again.
 *
 * The bench never touches a configured provider. It refuses a tenant whose
 * tenant scope already has an active connection, because activating the bench
 * connection there would deactivate it, and the adapter is handed to the
 * runner directly rather than looked up in the registry.
 *
 * Every refusal and failure is a reason code; exception text never reaches the
 * report, which an operator may paste into a ticket.
 */
final class WorkforceSyncBench
{
    public const PROVIDER_ID = 'bench.synthetic';

    public const CAPABILITY = 'people-connector.connection.manage';

    private const PROJECTION_TABLES = [
        'people_connector_connector_workforce_companies',
        'people_connector_connector_workforce_organization_units',
        'people_connector_connector_workforce_positions',
        'people_connector_connector_workforce_employees',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderConnectionStore $connections,
        private readonly SchedulerPrincipal $principals,
        private readonly SchedulerPrincipalGrants $grants,
        private readonly WorkforceSyncRunner $runner,
    ) {}

    /** @return array<string, mixed> */
    public function run(Actor $operator, int $employees, int $units, int $runs): array
    {
        $report = ['employees' => $employees, 'units' => $units, 'failure' => null];

        foreach (['employees' => $employees, 'units' => $units, 'runs' => $runs] as $option => $value) {
            if ($value < 1) {
                return ['failure' => "invalid_{$option}"] + $report;
            }
        }

        $tenantId = $this->tenants->requireTenantId();

        try {
            $this->authorization->authorize($operator, self::CAPABILITY);
        } catch (AuthorizationDeniedException) {
            return ['failure' => 'unauthorized'] + $report;
        }
        if ($operator->validate() !== null || $operator->tenantId !== $tenantId) {
            return ['failure' => 'unauthorized'] + $report;
        }

        if ($this->connections->active(ProviderScope::tenant()) !== null) {
            return ['failure' => 'provider_configured'] + $report;
        }
        if (ProviderConnection::query()->forTenant($tenantId)->where('provider_id', self::PROVIDER_ID)->exists()) {
            return ['failure' => 'bench_residue'] + $report;
        }

        $before = $this->ownedCounts();
        $connection = $this->connections->activate((int) $this->connections->configure(ProviderScope::tenant(), self::PROVIDER_ID, label: 'connector:bench:sync')->id);
        $connectionId = (int) $connection->id;
        $adapter = new SyntheticWorkforceAdapter(self::PROVIDER_ID, $employees, $units, \DateTimeImmutable::createFromInterface(now()));
        $actor = $this->principals->forConnection($connection);
        $report['runs'] = [];

        try {
            for ($run = 1; $run <= $runs; $run++) {
                $written = $this->projectionCounts($tenantId, $connectionId);
                memory_reset_peak_usage();
                $started = hrtime(true);
                $pass = $this->runner->bootstrap($actor, $adapter, $connectionId);
                $wall = (int) ((hrtime(true) - $started) / 1_000_000);
                $after = $this->projectionCounts($tenantId, $connectionId);

                $report['runs'][] = [
                    'run' => $run,
                    'wall_ms' => $wall,
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                    'pages' => $pass->pages,
                    'conflicts' => $pass->conflicts,
                    'rows_written' => array_map(static fn (string $table): int => $after[$table] - $written[$table], array_combine(self::PROJECTION_TABLES, self::PROJECTION_TABLES)),
                ];
            }
        } catch (\Throwable $failure) {
            $report['failure'] = match (true) {
                $failure instanceof CorruptWorkforcePageException => 'page_corrupt',
                $failure instanceof WorkforceSyncException => 'sync_failed',
                default => 'run_failed',
            };
        } finally {
            $this->teardown($tenantId, $connection);
        }

        $walls = array_column($report['runs'], 'wall_ms');
        sort($walls);
        $report['p50_ms'] = self::percentile($walls, 50);
        $report['p95_ms'] = self::percentile($walls, 95);

        $after = $this->ownedCounts();
        $report['teardown'] = [
            'restored' => $after === $before,
            'tables' => array_map(static fn (string $table): array => ['before' => $before[$table], 'after' => $after[$table]], array_combine(array_keys($before), array_keys($before))),
        ];
        if ($report['failure'] === null && $after !== $before) {
            $report['failure'] = 'teardown_incomplete';
        }

        return $report;
    }

    /**
     * Remove what the bench wrote, children before parents so every restrict
     * foreign key is satisfied. Query-builder deletes on purpose: the audit,
     * snapshot and checkpoint-event models refuse deletion for real history,
     * and these rows are a load test's, addressed by the throwaway connection.
     */
    private function teardown(int $tenantId, ProviderConnection $connection): void
    {
        $connectionId = (int) $connection->id;
        $identities = static fn (Builder $query): Builder => $query->select('id')
            ->from('people_connector_connector_external_identities')
            ->where('tenant_id', $tenantId)->where('connection_id', $connectionId);

        DB::transaction(function () use ($tenantId, $connectionId, $connection, $identities): void {
            $entityIds = DB::table('people_connector_connector_external_identities')
                ->where('tenant_id', $tenantId)->where('connection_id', $connectionId)
                ->distinct()->pluck('workforce_entity_id')->map(intval(...))->all();

            foreach (self::PROJECTION_TABLES as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->whereIn('source_identity_id', $identities)->delete();
            }
            DB::table('people_connector_connector_workforce_snapshots')->where('tenant_id', $tenantId)->where('connection_id', $connectionId)->delete();
            DB::table('people_connector_connector_sync_checkpoint_events')->where('tenant_id', $tenantId)
                ->whereIn('checkpoint_id', static fn (Builder $query): Builder => $query->select('id')
                    ->from('people_connector_connector_sync_checkpoints')
                    ->where('tenant_id', $tenantId)->where('connection_id', $connectionId))
                ->delete();
            foreach (['people_connector_connector_sync_checkpoints', 'people_connector_connector_reconciliation_issues', 'people_connector_connector_external_identities', 'people_connector_connector_operator_audits'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->where('connection_id', $connectionId)->delete();
            }
            foreach (array_chunk($entityIds, 500) as $chunk) {
                DB::table('people_connector_connector_workforce_entities')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
            }

            $this->grants->revoke($connection);
            DB::table('people_connector_connector_provider_connections')->where('tenant_id', $tenantId)->where('id', $connectionId)->delete();
        });
    }

    /** Whole-table counts of every connector-owned table, keyed by table name. @return array<string, int> */
    private function ownedCounts(): array
    {
        $counts = [];
        foreach (DomainModels::all() as $model) {
            $table = (new $model)->getTable();
            $counts[$table] = DB::table($table)->count();
        }
        ksort($counts);

        return $counts;
    }

    /** Projection rows the bench connection owns, per projection table. @return array<string, int> */
    private function projectionCounts(int $tenantId, int $connectionId): array
    {
        $counts = [];
        foreach (self::PROJECTION_TABLES as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)
                ->whereIn('source_identity_id', static fn (Builder $query): Builder => $query->select('id')
                    ->from('people_connector_connector_external_identities')
                    ->where('tenant_id', $tenantId)->where('connection_id', $connectionId))
                ->count();
        }

        return $counts;
    }

    /** Nearest-rank percentile of an ascending list; null when there were no runs. @param list<int> $sorted */
    private static function percentile(array $sorted, int $percent): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[max(0, (int) ceil($percent / 100 * count($sorted)) - 1)];
    }
}
