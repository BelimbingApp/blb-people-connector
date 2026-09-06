<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use App\Domains\PeopleConnector\Connector\Services\WebhookDeliveryReplayer;
use Illuminate\Console\Command;

/**
 * Re-dispatch one failed webhook delivery by id (#223).
 *
 * Exits non-zero whenever nothing was sent, so a runbook step that replays a
 * delivery cannot report success for a refusal.
 */
final class WebhookReplayCommand extends Command
{
    protected $signature = 'connector:webhook:replay
                            {delivery : Id of the webhook delivery to replay}
                            {--tenant= : Tenant the delivery belongs to; defaults to the current tenant context}
                            {--as= : Id of the operator this replay runs as}
                            {--dry-run : Print what would be sent without sending or recording anything}';

    protected $description = 'Re-dispatch one failed webhook delivery for the operator\'s tenant, with an audit row';

    public function handle(TenantContext $tenants, WebhookDeliveryReplayer $replayer): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A webhook replay runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        if (($tenantId = $this->option('tenant')) !== null && $tenantId !== '') {
            $tenants->set((int) $tenantId);
        }

        $actor = Actor::forUser($operator);
        $deliveryId = (int) $this->argument('delivery');

        try {
            if ($this->option('dry-run')) {
                $plan = $replayer->plan($actor, $deliveryId);
                $this->line("Dry run: webhook delivery {$plan->original->id} would be re-sent. Nothing was sent or recorded.");
                $this->table(['Field', 'Value'], $plan->rows());

                return self::SUCCESS;
            }

            $replay = $replayer->replay($actor, $deliveryId);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|WebhookRefusal $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Replayed webhook delivery {$deliveryId} as delivery {$replay->id} on connection {$replay->connection_id} (tenant {$replay->tenant_id}); an audit row names operator {$operator->id}.");

        return self::SUCCESS;
    }
}
