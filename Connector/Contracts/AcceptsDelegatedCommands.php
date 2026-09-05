<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;

/**
 * The one door an employee command goes through, whatever carried it here.
 *
 * There is deliberately no business method on this port yet: [1010-b] is the
 * boundary, and leave or attendance behaviour arrives behind it later. What
 * matters now is that in-process callers and the HTTP controller reach the same
 * accept(), so the backend recheck cannot be true of one path and not the other.
 */
interface AcceptsDelegatedCommands
{
    /**
     * @throws DelegatedAuthorityException
     */
    public function accept(DelegatedAuthority $authority, string $operation): DelegatedAuthority;
}
