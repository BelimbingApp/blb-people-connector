<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WebhookReplayPlan;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Re-sends one failed webhook delivery for the acting operator's tenant (#223).
 *
 * The queue retries a failing pass on its own; this is for the case after a
 * fix, when an operator wants that specific delivery run again and wants the
 * decision on record. A replay is a new delivery row pointing at the original,
 * so the original's failure stays as evidence, and one audit row names the
 * operator and both deliveries. What is sent is the trigger the callback
 * produced (tenant, connection), never provider bytes: the ledger holds none.
 */
final class WebhookDeliveryReplayer
{
    public const REPLAY_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audit,
    ) {}

    /** Everything a replay checks, without sending or recording anything. */
    public function plan(Actor $actor, int $deliveryId): WebhookReplayPlan
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::REPLAY_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'replay_webhook',
                message: 'Replaying a webhook delivery requires an operator inside the current tenant.',
            );
        }

        $original = WebhookDelivery::query()->forTenant($tenantId)->find($deliveryId)
            ?? throw new ConnectorRecordNotFoundException('The webhook delivery was not found in the current tenant.');

        if ($original->status !== WebhookDelivery::STATUS_FAILED) {
            throw new WebhookRefusal('not_replayable', "Webhook delivery {$original->id} is {$original->status}; only a failed delivery can be replayed.");
        }

        $connection = $this->connections->get((int) $original->connection_id);

        if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new WebhookRefusal('inactive_connection', "Provider connection {$connection->id} is not active; a replay would only fail again.");
        }

        return new WebhookReplayPlan($original, $tenantId, (int) $connection->id);
    }

    public function replay(Actor $actor, int $deliveryId): WebhookDelivery
    {
        $plan = $this->plan($actor, $deliveryId);
        $original = $plan->original;

        return DB::transaction(function () use ($actor, $plan, $original): WebhookDelivery {
            $replay = WebhookDelivery::query()->create([
                'tenant_id' => $plan->tenantId,
                'connection_id' => $plan->connectionId,
                'delivery_id' => $original->delivery_id,
                'status' => WebhookDelivery::STATUS_ACCEPTED,
                'replayed_from_id' => $original->id,
                'received_at' => now(),
            ]);

            $this->audit->record(
                $actor,
                OperatorAuditOperation::WebhookReplayed,
                $plan->connectionId,
                null,
                null,
                ['delivery' => $original->id, 'provider_delivery_id' => $original->delivery_id, 'status' => $original->status, 'attempts' => $original->attempts, 'failure_reason' => $original->failure_reason?->value],
                ['delivery' => $replay->id, 'status' => $replay->status, 'queue' => RunIncrementalWorkforceSync::QUEUE],
            );

            RunIncrementalWorkforceSync::dispatch($plan->tenantId, $plan->connectionId, (int) $replay->id);

            return $replay;
        });
    }
}
