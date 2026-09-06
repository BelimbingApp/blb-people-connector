<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use Throwable;

/**
 * A verified provider callback and the fate of the sync pass it triggered.
 *
 * `accepted` means the pass is queued, `delivered` that it completed, and
 * `failed` that its last attempt threw. Only a failed delivery can be
 * replayed (WebhookDeliveryReplayer); the replay is a new row whose
 * `replayed_from_id` names this one.
 */
final class WebhookDelivery extends TenantOwnedModel
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $table = 'people_connector_connector_webhook_deliveries';

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'attempts' => ((int) $this->attempts) + 1,
            'last_error' => null,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(Throwable $failure): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempts' => ((int) $this->attempts) + 1,
            'last_error' => mb_substr($failure::class.': '.$failure->getMessage(), 0, 191),
            'failed_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'attempts' => 'integer',
            'replayed_from_id' => 'integer',
            'received_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
