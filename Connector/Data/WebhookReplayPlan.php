<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;

/** What a webhook replay would send: the trigger, never the provider's bytes (#223). */
final readonly class WebhookReplayPlan
{
    public function __construct(
        public WebhookDelivery $original,
        public int $tenantId,
        public int $connectionId,
    ) {}

    /** @return list<array{string, string}> */
    public function rows(): array
    {
        return [
            ['Delivery', (string) $this->original->id],
            ['Provider delivery id', (string) $this->original->delivery_id],
            ['Status', (string) $this->original->status],
            ['Attempts', (string) $this->original->attempts],
            ['Last error', (string) ($this->original->last_error ?? '')],
            ['Tenant', (string) $this->tenantId],
            ['Connection', (string) $this->connectionId],
            ['Job', RunIncrementalWorkforceSync::class],
            ['Queue', RunIncrementalWorkforceSync::QUEUE],
        ];
    }
}
