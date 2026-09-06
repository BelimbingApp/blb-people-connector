<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Enums\WebhookDeliveryFailure;
use Throwable;

/**
 * A verified provider callback and the fate of the sync pass it triggered.
 *
 * `accepted` means the pass is queued, `delivered` that it completed,
 * `failed` that its latest retryable attempt threw, and `dead_lettered` that
 * the connection's retry budget ended. Failures keep a reason code and the
 * exception class, never the message. Failed and dead-lettered deliveries can
 * be replayed (WebhookDeliveryReplayer); the replay is a new row whose
 * `replayed_from_id` names this one.
 */
final class WebhookDelivery extends TenantOwnedModel
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTERED = 'dead_lettered';

    protected $table = 'people_connector_connector_webhook_deliveries';

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'attempts' => ((int) $this->attempts) + 1,
            'failure_reason' => null,
            'failure_class' => null,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(Throwable $failure, bool $deadLettered = false): void
    {
        $this->forceFill([
            'status' => $deadLettered ? self::STATUS_DEAD_LETTERED : self::STATUS_FAILED,
            'attempts' => ((int) $this->attempts) + 1,
            'failure_reason' => WebhookDeliveryFailure::for($failure),
            'failure_class' => mb_substr($failure::class, 0, 191),
            'failed_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'attempts' => 'integer',
            'failure_reason' => WebhookDeliveryFailure::class,
            'replayed_from_id' => 'integer',
            'received_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
