<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;

final class ProviderHealthMonitor
{
    public function __construct(private ProviderHealthStore $store) {}

    public function refresh(ProviderAdapter $provider): ProviderHealth
    {
        $providerId = $provider->descriptor()->id;
        $previous = $this->store->snapshot($providerId);

        try {
            $health = $provider->health();
        } catch (\Throwable) {
            $health = new ProviderHealth(
                state: ProviderHealthState::Unavailable,
                checkedAt: new \DateTimeImmutable,
                lastSuccessfulSyncAt: $previous->lastSuccessfulSyncAt,
                message: 'The provider health check failed. Review the adapter diagnostics.',
            );
        }

        if ($health->state === ProviderHealthState::Unknown
            && $health->lastSuccessfulSyncAt === null
            && $previous->lastSuccessfulSyncAt !== null) {
            $health = new ProviderHealth(
                state: $health->state,
                checkedAt: $health->checkedAt,
                lastSuccessfulSyncAt: $previous->lastSuccessfulSyncAt,
                message: $health->message,
            );
        }

        $this->store->record($providerId, $health);

        return $health;
    }
}
