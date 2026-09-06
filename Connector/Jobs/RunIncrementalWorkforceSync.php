<?php

namespace App\Domains\PeopleConnector\Connector\Jobs;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the ordinary incremental pass after a verified provider callback.
 *
 * Only stable tenant and connection identities cross the queue boundary. The
 * provider payload is intentionally absent: the callback is a trigger, never
 * a second projection-write path.
 */
final class RunIncrementalWorkforceSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'people-connector-sync';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $connectionId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        TenantContext $tenants,
        TenantConnectionLocator $connections,
        ProviderRegistry $registry,
        SchedulerPrincipal $principals,
        WorkforceSyncRunner $runner,
    ): void {
        $tenants->set($this->tenantId);

        try {
            $connection = $connections->get($this->connectionId);
            if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
                throw new WorkforceSyncException("Provider connection {$this->connectionId} is not active.");
            }

            $provider = $registry->find((string) $connection->provider_id)
                ?? throw new WorkforceSyncException("Provider '{$connection->provider_id}' is not registered.");

            $runner->incremental(
                $principals->forConnection($connection),
                $provider,
                $this->connectionId,
            );
        } finally {
            $tenants->clear();
        }
    }
}
