<?php

namespace App\Domains\PeopleConnector\Connector\Http\Controllers;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use App\Domains\PeopleConnector\Connector\Services\WorkforceWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceWebhookController
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantConnectionLocator $connections,
        private readonly WorkforceWebhookVerifier $verifier,
    ) {}

    public function __invoke(Request $request, int $connectionId): JsonResponse
    {
        if (! config('people-connector.webhook.enabled', false)) {
            return new JsonResponse(['refused' => 'disabled'], 404);
        }

        try {
            $connection = $this->connections->getForWebhook($connectionId);

            if ($this->tenants->currentTenantId() === null) {
                $this->tenants->set((int) $connection->tenant_id);
            }

            $tenantId = $this->tenants->requireTenantId();

            if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
                throw new WebhookRefusal('unknown_connection', 'The provider connection was not found.');
            }

            $body = $request->getContent();
            $deliveryId = $request->header(WorkforceWebhookVerifier::DELIVERY_HEADER);

            $this->verifier->verify(
                $connectionId,
                $body,
                $request->header(WorkforceWebhookVerifier::TIMESTAMP_HEADER),
                $deliveryId,
                $request->header(WorkforceWebhookVerifier::SIGNATURE_HEADER),
            );

            $this->verifier->enqueueOnce(
                $connectionId,
                (string) $deliveryId,
                function () use ($tenantId, $connectionId, $deliveryId): void {
                    // The row is the operator's handle for a replay (#223); it
                    // records the trigger, never the provider's bytes.
                    $delivery = WebhookDelivery::query()->create([
                        'tenant_id' => $tenantId,
                        'connection_id' => $connectionId,
                        'delivery_id' => (string) $deliveryId,
                        'status' => WebhookDelivery::STATUS_ACCEPTED,
                        'received_at' => now(),
                    ]);
                    RunIncrementalWorkforceSync::dispatch($tenantId, $connectionId, (int) $delivery->id);
                },
            );
        } catch (ConnectorRecordNotFoundException|WebhookRefusal $refused) {
            $status = match ($refused instanceof WebhookRefusal ? $refused->reason : null) {
                'unconfigured' => 503,
                'payload_too_large' => 413,
                default => 403,
            };

            return new JsonResponse(['refused' => $refused instanceof WebhookRefusal ? $refused->reason : 'unknown_connection'], $status);
        }

        return new JsonResponse(['queued' => true], 202);
    }
}
