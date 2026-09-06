<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
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
 * enqueue. The insert runs outside the work's transaction on purpose: a
 * unique violation inside a transaction poisons it on PostgreSQL. If the
 * work then fails, the receipt is released so the provider's retry is
 * processed rather than acknowledged as a duplicate.
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
            $receipt = WebhookReceipt::query()->create([...$key, 'connection_id' => (int) $connection->id, 'first_seen_at' => now()]);
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

    /** Deliveries acknowledged as duplicates for this tenant in the last $days days. */
    public function duplicatesSkipped(int $tenantId, int $days = 7): int
    {
        return (int) WebhookReceipt::query()->forTenant($tenantId)
            ->where('first_seen_at', '>=', now()->subDays($days))
            ->sum('duplicate_count');
    }
}
