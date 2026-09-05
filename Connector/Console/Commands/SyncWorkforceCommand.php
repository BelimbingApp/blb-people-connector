<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\WorkforceSyncReport;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Console\Command;

/**
 * Headless entry for WorkforceSyncRunner (#70).
 *
 * Mints a SCHEDULER actor from the connection, never a user session. With no
 * connection argument, every active connection in every tenant is synced.
 * Incremental is the default; --bootstrap forces a bootstrap pass. When the
 * scheduler runs incremental and no checkpoint exists yet, bootstrap runs
 * once so the first schedule tick is not a permanent failure.
 */
final class SyncWorkforceCommand extends Command
{
    protected $signature = 'people-connector:sync
                            {connection? : Provider connection id}
                            {--bootstrap : Run the bootstrap pass instead of incremental}
                            {--tenant= : Limit to one tenant when syncing all connections}';

    protected $description = 'Synchronise workforce projections for an active provider connection';

    public function handle(
        TenantContext $tenants,
        ProviderRegistry $registry,
        WorkforceSyncRunner $runner,
        SchedulerPrincipal $principals,
        SyncCheckpointStore $checkpoints,
    ): int {
        $connectionId = $this->argument('connection');
        $bootstrap = (bool) $this->option('bootstrap');
        $tenantFilter = $this->option('tenant');

        $connections = $this->connectionsToSync(
            $connectionId !== null ? (int) $connectionId : null,
            $tenantFilter !== null && $tenantFilter !== '' ? (int) $tenantFilter : null,
        );

        if ($connections === []) {
            $this->warn('No active provider connections matched.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($connections as $connection) {
            $tenants->set((int) $connection->tenant_id);

            try {
                $provider = $registry->find((string) $connection->provider_id)
                    ?? throw new WorkforceSyncException(
                        "Provider '{$connection->provider_id}' is not registered for connection {$connection->id}.",
                    );
                $actor = $principals->forConnection($connection);
                $report = $this->runPass($runner, $checkpoints, $actor, $provider, $connection, $bootstrap);
                $this->line($this->formatReport($report));

                if ($report->feedRefused()) {
                    $this->error("Connection {$connection->id}: feed refused — checkpoint not advanced.");
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
                // Adapter exceptions may contain credentials or raw HR responses.
                $this->error("Connection {$connection->id}: synchronization failed. Check provider availability and connection configuration.");
            } finally {
                $tenants->clear();
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<ProviderConnection>
     */
    private function connectionsToSync(?int $connectionId, ?int $tenantId): array
    {
        $query = ProviderConnection::query()
            ->where('status', ProviderConnection::STATUS_ACTIVE)
            ->orderBy('id');

        if ($connectionId !== null) {
            $query->whereKey($connectionId);
        }

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->all();
    }

    private function runPass(
        WorkforceSyncRunner $runner,
        SyncCheckpointStore $checkpoints,
        Actor $actor,
        ProviderAdapter $provider,
        ProviderConnection $connection,
        bool $bootstrap,
    ): WorkforceSyncReport {
        $connectionId = (int) $connection->id;

        if ($bootstrap) {
            return $runner->bootstrap($actor, $provider, $connectionId);
        }

        $stream = WorkforceFreshnessPolicy::stream();
        $hasCheckpoint = $checkpoints->current($connectionId, $stream) !== null;

        if (! $hasCheckpoint) {
            $this->info("Connection {$connectionId}: no checkpoint yet — running bootstrap.");

            return $runner->bootstrap($actor, $provider, $connectionId);
        }

        return $runner->incremental($actor, $provider, $connectionId);
    }

    private function formatReport(WorkforceSyncReport $report): string
    {
        return sprintf(
            'connection=%d pass=%s pages=%d applied=%d conflicts=%d merges=%d checkpoint=%d advanced=%s as_of=%s',
            $report->connectionId,
            $report->pass,
            $report->pages,
            $report->applied(),
            $report->conflicts,
            $report->mergesQueued,
            $report->checkpointVersion,
            $report->checkpointAdvanced ? 'yes' : 'no',
            $report->asOf->format(DATE_ATOM),
        );
    }
}
