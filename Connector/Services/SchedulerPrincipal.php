<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Mints the per-connection SCHEDULER actor for headless workforce sync
 * (BelimbingApp/blb-people#78 / connector #70).
 *
 * Not a credential: built at dispatch from the connection row and discarded
 * when the pass ends. Company-scoped connections carry company_id; tenant-
 * scoped connections leave companyId null (process actors may omit it).
 */
final class SchedulerPrincipal
{
    public function forConnection(ProviderConnection $connection): Actor
    {
        return new Actor(
            type: PrincipalType::SCHEDULER,
            id: (int) $connection->id,
            companyId: $connection->company_id === null ? null : (int) $connection->company_id,
            tenantId: (int) $connection->tenant_id,
        );
    }
}
