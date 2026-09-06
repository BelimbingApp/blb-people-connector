<?php

namespace App\Domains\PeopleConnector\Connector\Http\Controllers;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
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

            $this->verifier->verify(
                $connectionId,
                $request->getContent(),
                $request->header(WorkforceWebhookVerifier::TIMESTAMP_HEADER),
                $request->header(WorkforceWebhookVerifier::SIGNATURE_HEADER),
            );

            RunIncrementalWorkforceSync::dispatch($tenantId, $connectionId);
        } catch (ConnectorRecordNotFoundException|WebhookRefusal $refused) {
            $status = $refused instanceof WebhookRefusal && $refused->reason === 'unconfigured' ? 503 : 403;

            return new JsonResponse(['refused' => $refused instanceof WebhookRefusal ? $refused->reason : 'unknown_connection'], $status);
        }

        return new JsonResponse(['queued' => true], 202);
    }
}
