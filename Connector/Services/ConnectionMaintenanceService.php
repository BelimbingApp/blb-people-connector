<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionMaintenanceException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Illuminate\Support\Facades\DB;

/**
 * Put one provider connection into, or take it out of, a planned maintenance
 * window (#264).
 *
 * A window is a pause, not a state change: the connection stays active, its
 * scheduler grants stay, and nothing it recorded is touched. While the window
 * holds, sync passes skip the connection and webhook-triggered runs are
 * deferred for replay afterwards. Retirement is one-way and is not a pause,
 * so a retired connection cannot enter a window.
 *
 * The window is bounded: at most MAX_DAYS ahead, so a forgotten window lapses
 * on its own rather than silencing a provider for good. Each change leaves
 * one operator audit row with the window before and after.
 */
final class ConnectionMaintenanceService
{
    public const MANAGE_CAPABILITY = 'people-connector.connection.manage';

    public const MAX_DAYS = 7;

    public const MAX_REASON_LENGTH = 190;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function start(Actor $actor, int $connectionId, \DateTimeImmutable $until, ?string $reason = null): ProviderConnection
    {
        $tenantId = $this->authorize($actor, 'start_maintenance');
        $now = \DateTimeImmutable::createFromInterface(now());
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($until <= $now) {
            throw new ConnectionMaintenanceException('A maintenance window must end in the future.');
        }
        if ($until > $now->modify('+'.self::MAX_DAYS.' days')) {
            throw new ConnectionMaintenanceException('A maintenance window may last at most '.self::MAX_DAYS.' days.');
        }
        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new ConnectionMaintenanceException('A maintenance reason is at most '.self::MAX_REASON_LENGTH.' characters.');
        }

        return $this->change($actor, $tenantId, $connectionId, $until, $reason, $now);
    }

    public function end(Actor $actor, int $connectionId): ProviderConnection
    {
        $tenantId = $this->authorize($actor, 'end_maintenance');

        return $this->change($actor, $tenantId, $connectionId, null, null, \DateTimeImmutable::createFromInterface(now()));
    }

    private function change(Actor $actor, int $tenantId, int $connectionId, ?\DateTimeImmutable $until, ?string $reason, \DateTimeImmutable $now): ProviderConnection
    {
        // Resolve before the transaction so an unknown or foreign id is refused
        // the way every other operator command refuses it.
        $this->connections->get($connectionId);

        return DB::transaction(function () use ($actor, $tenantId, $connectionId, $until, $reason, $now): ProviderConnection {
            $connection = ProviderConnection::query()
                ->forTenant($tenantId)
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status === ProviderConnection::STATUS_RETIRED) {
                throw new ConnectionMaintenanceException("Provider connection {$connectionId} is retired; retirement is not a pause.");
            }

            // A lapsed window reads as none, and is cleared here on the way past.
            $connection->inMaintenance($now);
            $before = $this->window($connection);

            $connection->forceFill(['maintenance_until' => $until, 'maintenance_reason' => $reason])->save();

            $this->audit->record(
                $actor,
                OperatorAuditOperation::ConnectionMaintenance,
                $connectionId,
                null,
                null,
                $before,
                $this->window($connection),
                $now,
            );

            return $connection->refresh();
        });
    }

    /** @return array{maintenance_until: ?string, maintenance_reason: ?string} */
    private function window(ProviderConnection $connection): array
    {
        return [
            'maintenance_until' => $connection->maintenance_until?->format(DATE_ATOM),
            'maintenance_reason' => $connection->maintenance_reason,
        ];
    }

    private function authorize(Actor $actor, string $operation): int
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::MANAGE_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: $operation,
                message: 'Changing a maintenance window requires an operator inside the current tenant.',
            );
        }

        return $tenantId;
    }
}
