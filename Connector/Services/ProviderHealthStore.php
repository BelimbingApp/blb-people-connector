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
 * instance (app/Base/Tenancy/ServiceProvider.php:31) and Octane's
 * FlushTemporaryContainerInstances discards scoped instances at every request,
 * job, and command boundary.
 *
 * The rule that follows from that is about lifetime, not about injection: no
 * object that outlives one execution may hold the tenant context. Injecting it
 * is perfectly safe for an object that does not outlive one, which is why the
 * platform's three injectors — TenantStoragePath, PlatformOperatorTenantAccess
 * and the ResolveTenantContext middleware — and the seven sibling Connector
 * services on the persistence branch are all fine: none of them is bound, so
 * every resolution builds a new one around a live context.
 *
 * This store is the case that does outlive an execution, because it is
 * registered as a singleton. Rather than shorten its lifetime, it resolves the
 * tenant at the point of use on every call, the way all 51 platform call sites
 * do (Core/User, Core/Company, Base/Authz, Base/Audit and the rest). That
 * keeps it correct under any container lifetime instead of only under the one
 * it happens to be bound with today.
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
        // Invalidate entries written before adapter messages were excluded.
        return sprintf(
            'people-connector:tenant:%d:provider:%s:health:v2',
            app(TenantContext::class)->requireTenantId(),
            hash('sha256', $providerId),
        );
    }
}
