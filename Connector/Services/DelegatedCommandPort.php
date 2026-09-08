<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;

/**
 * The backend recheck, run for every transport.
 *
 * A signature proves the claims were not altered on the way here. It does not
 * say the holder may act on this tenant, for this operation, at this service,
 * now, for the first time — so this asks all of that, and it asks it after
 * verification rather than instead of it. The spend is last: a refused
 * authority is not consumed, and an accepted one is consumed exactly once.
 */
final class DelegatedCommandPort implements AcceptsDelegatedCommands
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DelegatedAuthorityLedger $ledger,
    ) {}

    public function accept(DelegatedAuthority $authority, string $operation): DelegatedAuthority
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $authority->assertUsableBy($tenantId, $operation);

        // The wire checks the audience it was addressed on; an in-process
        // caller has no wire, so the port asks against the service's own
        // configured name. Without this, an authority minted for another
        // service was refused at the door and accepted through the window.
        if (! hash_equals(DelegationPolicy::audience(), $authority->audience)) {
            throw new DelegatedAuthorityException(
                "This authority is addressed to [{$authority->audience}], not this service.",
                DelegatedAuthorityRefusal::WrongAudience,
            );
        }

        if (! $this->ledger->spend($tenantId, $authority)) {
            throw new DelegatedAuthorityException('This authority has already been spent.', DelegatedAuthorityRefusal::Replayed);
        }

        return $authority;
    }
}
