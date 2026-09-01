<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use Illuminate\Contracts\Cache\Repository;

/**
 * Tenant-scoped cache of the last observed provider health.
 *
 * The cache repository lives for the whole worker process, so it is injected.
 * The tenant context does not: the platform binds TenantContext as a scoped
 * instance (app/Base/Tenancy/ServiceProvider.php) and Octane's
 * FlushTemporaryContainerInstances discards scoped instances at every request,
 * job, and command boundary. Anything that stores the context object outlives
 * it and goes on answering for whichever tenant happened to arrive first.
 *
 * So the tenant is resolved at the point of use, on every call, exactly as the
 * rest of the platform does it (App\Base\Media\Services\MediaAssetStore and
 * every tenant-aware Core model and Livewire component resolve
 * TenantContext through the container per call). That keeps this class correct
 * under any container lifetime rather than only under the one it happens to be
 * bound with today.
 */
final class ProviderHealthStore
{
    public function __construct(
        private Repository $cache,
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
            app(TenantContext::class)->requireTenantId(),
            hash('sha256', $providerId),
        );
    }
}
