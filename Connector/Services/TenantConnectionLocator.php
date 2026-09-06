<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Resolves a tenant-scoped provider connection, optionally requesting a row lock.
 *
 * `$lock = true` is the PostgreSQL serialisation path. On SQLite it is a no-op —
 * see docs/contracts/store-concurrency.md (#12).
 */
final class TenantConnectionLocator
{
    public function __construct(private TenantContext $tenantContext) {}

    public function get(int $connectionId, bool $lock = false): ProviderConnection
    {
        $query = ProviderConnection::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->whereKey($connectionId);

        // PostgreSQL: FOR UPDATE. SQLite: no-op (docs/contracts/store-concurrency.md).
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw new ConnectorRecordNotFoundException('The provider connection was not found in the current tenant.');
    }

    /**
     * Resolve a connection for an inbound provider callback.
     *
     * Guest webhook requests have no authenticated user for the web tenant
     * middleware to resolve. A connection id is the callback's tenant anchor;
     * once a tenant is already present, retain the normal scoped lookup so a
     * request cannot cross that boundary.
     */
    public function getForWebhook(int $connectionId): ProviderConnection
    {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId !== null) {
            return $this->get($connectionId);
        }

        return ProviderConnection::query()
            ->whereKey($connectionId)
            ->first()
            ?? throw new ConnectorRecordNotFoundException('The provider connection was not found.');
    }
}
