<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use Illuminate\Contracts\Cache\Repository;

final class ProviderHealthStore
{
    public function __construct(
        private Repository $cache,
        private TenantContext $tenantContext,
    ) {}

    public function record(string $providerId, ProviderHealth $health): void
    {
        $this->cache->forever($this->key($providerId), $health);
    }

    public function snapshot(string $providerId): ProviderHealth
    {
        $health = $this->cache->get($this->key($providerId));

        return $health instanceof ProviderHealth
            ? $health
            : new ProviderHealth(
                state: ProviderHealthState::Unknown,
                checkedAt: null,
                message: 'Health has not been checked yet.',
            );
    }

    private function key(string $providerId): string
    {
        return sprintf(
            'people-connector:tenant:%d:provider:%s:health',
            $this->tenantContext->requireTenantId(),
            hash('sha256', $providerId),
        );
    }
}
