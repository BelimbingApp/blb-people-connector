<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Collection;

/** Read-only tenant inventory for webhook deliveries whose retry budget ended. */
final class WebhookDeadLetterService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    /** @return Collection<int, WebhookDelivery> */
    public function forActor(Actor $actor): Collection
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, WebhookDeliveryReplayer::REPLAY_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'list_webhook_dead_letters',
                message: 'Listing webhook dead letters requires an operator inside the current tenant.',
            );
        }

        return WebhookDelivery::query()
            ->forTenant($tenantId)
            ->where('status', WebhookDelivery::STATUS_DEAD_LETTERED)
            ->orderBy('failed_at')
            ->orderBy('id')
            ->get();
    }
}
