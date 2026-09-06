<?php

namespace App\Domains\PeopleConnector\Connector\Jobs;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WebhookDeliveryPolicy;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the ordinary incremental pass after a verified provider callback.
 *
 * Only stable tenant and connection identities cross the queue boundary. The
 * provider payload is intentionally absent: the callback is a trigger, never
 * a second projection-write path. When the trigger was a recorded webhook
 * delivery (#223) the job reports the pass's fate back to that row so an
 * operator can replay a failed one by id.
 */
final class RunIncrementalWorkforceSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'people-connector-sync';

    public int $tries;

    /** @var list<int> */
    private array $backoffSeconds;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $connectionId,
        public readonly ?int $deliveryId = null,
        ?WebhookDeliveryPolicy $deliveryPolicy = null,
    ) {
        if ($deliveryId === null) {
            $this->tries = 1;
            $this->backoffSeconds = [];
            $this->onQueue(self::QUEUE);

            return;
        }

        $deliveryPolicy ??= WebhookDeliveryPolicy::defaults();
        $this->tries = $deliveryPolicy->maxAttempts;
        $this->backoffSeconds = $deliveryPolicy->backoffSeconds;
        $this->onQueue(self::QUEUE);
    }

    public static function forDelivery(ProviderConnection $connection, int $deliveryId): self
    {
        return new self(
            (int) $connection->tenant_id,
            (int) $connection->id,
            $deliveryId,
            WebhookDeliveryPolicy::forConnection($connection),
        );
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->backoffSeconds;
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
            $this->delivery()?->markDelivered();
        } catch (Throwable $failure) {
            $delivery = $this->delivery();
            $deadLettered = $delivery !== null && $this->attempts() >= $this->tries;
            $delivery?->markFailed($failure, $deadLettered);

            if ($deadLettered) {
                $this->fail($failure);

                return;
            }

            throw $failure;
        } finally {
            $tenants->clear();
        }
    }

    private function delivery(): ?WebhookDelivery
    {
        if ($this->deliveryId === null) {
            return null;
        }

        return WebhookDelivery::query()->forTenant($this->tenantId)->find($this->deliveryId);
    }
}
