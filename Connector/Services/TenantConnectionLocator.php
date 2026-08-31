<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

final class TenantConnectionLocator
{
    public function __construct(private TenantContext $tenantContext) {}

    public function get(int $connectionId, bool $lock = false): ProviderConnection
    {
        $query = ProviderConnection::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->whereKey($connectionId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw new ConnectorRecordNotFoundException('The provider connection was not found in the current tenant.');
    }
}
