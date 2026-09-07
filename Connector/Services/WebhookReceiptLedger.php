<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Accepts each inbound delivery id once per tenant and provider (#227).
 *
 * The receipt insert is the reservation: the database's unique key decides
 * who arrived first, so two concurrent arrivals of one delivery cannot both
 * enqueue. The insert runs in its own (nested: savepoint) transaction and
 * before the work's: a unique violation poisons the PostgreSQL transaction
 * it happens in, so it must be one that is rolled back and nothing else. If
 * the work then fails, the receipt is released so the provider's retry is
 * processed rather than acknowledged as a duplicate.
 *
 * A process that dies between the two steps leaves a receipt with no
 * delivery: a stuck reservation, which the retry would read as a duplicate.
 * stuckReservations() makes that visible in connector:doctor (review on
 * #228); the ledger does not reclaim it on its own.
 */
final class WebhookReceiptLedger
{
    /**
     * @param  Closure(): void  $enqueue
     * @return bool false when the delivery was already accepted; it is counted, not re-run
     */
    public function acceptOnce(int $tenantId, ProviderConnection $connection, string $deliveryId, Closure $enqueue): bool
    {
        $key = ['tenant_id' => $tenantId, 'provider_id' => (string) $connection->provider_id, 'delivery_id' => $deliveryId];

        try {
            $receipt = DB::transaction(fn (): WebhookReceipt => WebhookReceipt::query()->create([...$key, 'connection_id' => (int) $connection->id, 'first_seen_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            WebhookReceipt::query()->forTenant($tenantId)->where($key)
                ->update(['duplicate_count' => DB::raw('duplicate_count + 1'), 'last_duplicate_at' => now()]);

            return false;
        }

        try {
            DB::transaction($enqueue);
        } catch (Throwable $failure) {
            WebhookReceipt::query()->forTenant($tenantId)->whereKey($receipt->id)->delete();

            throw $failure;
        }

        return true;
    }

    /**
     * Receipts older than the grace window with no delivery row behind them:
     * reservations whose enqueue never happened. The retry of such a delivery
     * is acknowledged as a duplicate, so without this count the loss is silent.
     */
    public function stuckReservations(int $tenantId, int $graceMinutes = 5): int
    {
        return WebhookReceipt::query()->forTenant($tenantId)
            ->where('first_seen_at', '<', now()->subMinutes($graceMinutes))
            ->whereNotExists(function ($query) use ($tenantId): void {
                $query->from((new WebhookDelivery)->getTable(), 'd')
                    ->whereColumn('d.connection_id', 'people_connector_connector_webhook_receipts.connection_id')
                    ->whereColumn('d.delivery_id', 'people_connector_connector_webhook_receipts.delivery_id')
                    ->where('d.tenant_id', $tenantId);
            })
            ->count();
    }

    /** Deliveries acknowledged as duplicates for this tenant in the last $days days. */
    public function duplicatesSkipped(int $tenantId, int $days = 7): int
    {
        return (int) WebhookReceipt::query()->forTenant($tenantId)
            ->where('first_seen_at', '>=', now()->subDays($days))
            ->sum('duplicate_count');
    }
}
