<?php

namespace App\Domains\PeopleConnector\Connector\Models;

/**
 * A delivery id a tenant has accepted from a provider (#227). The row's
 * existence is the idempotency check; `duplicate_count` is how often the
 * provider sent it again and was acknowledged without a second pass.
 */
final class WebhookReceipt extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_webhook_receipts';

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'duplicate_count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_duplicate_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
