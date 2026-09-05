<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;

/**
 * The backend recheck, run for every transport.
 *
 * A signature proves the claims were not altered on the way here. It does not
 * say the holder may act on this tenant, for this operation, now — so this asks
 * that, and it asks it after verification rather than instead of it.
 */
final class DelegatedCommandPort implements AcceptsDelegatedCommands
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function accept(DelegatedAuthority $authority, string $operation): DelegatedAuthority
    {
        $authority->assertUsableBy($this->tenantContext->requireTenantId(), $operation);

        return $authority;
    }
}
