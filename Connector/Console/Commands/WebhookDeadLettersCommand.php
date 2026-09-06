<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\WebhookDeadLetterService;
use App\Domains\PeopleConnector\Connector\Services\WebhookDeliveryReplayer;
use Illuminate\Console\Command;

final class WebhookDeadLettersCommand extends Command
{
    protected $signature = 'connector:webhook:dead-letters
                            {--tenant= : Tenant whose dead letters are listed; defaults to the current tenant context}
                            {--as= : Id of the operator reading or replaying the dead letters}
                            {--replay : Re-dispatch every listed dead letter through the audited replay path}';

    protected $description = 'List or replay dead-lettered webhook deliveries for the operator tenant';

    public function handle(
        TenantContext $tenants,
        WebhookDeadLetterService $deadLetters,
        WebhookDeliveryReplayer $replayer,
    ): int {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A dead-letter listing runs as a named operator: pass --as=<user id>.');

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

        try {
            $deliveries = $deadLetters->forActor($actor);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['id', 'connection', 'provider delivery id', 'attempts', 'failure', 'failed at'],
            $deliveries->map(static fn (WebhookDelivery $delivery): array => [
                $delivery->id,
                $delivery->connection_id,
                $delivery->delivery_id,
                $delivery->attempts,
                $delivery->failure_reason?->value ?? '',
                $delivery->failed_at?->utc()->format(DATE_ATOM) ?? '',
            ])->all(),
        );

        if (! $this->option('replay')) {
            return self::SUCCESS;
        }

        try {
            foreach ($deliveries as $delivery) {
                $replayer->replay($actor, (int) $delivery->id);
            }
        } catch (AuthorizationDeniedException|ConnectorRecordNotFoundException|ProviderAuthorizationException|WebhookRefusal $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $count = $deliveries->count();
        $this->info("Replayed {$count} dead-lettered ".($count === 1 ? 'delivery' : 'deliveries').'.');

        return self::SUCCESS;
    }
}
