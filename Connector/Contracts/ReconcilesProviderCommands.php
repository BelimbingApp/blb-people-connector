<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;

/**
 * Asks a provider whether it already holds a command sent under a given
 * idempotency key.
 *
 * An adapter implements this only if it can answer truthfully. Returning null
 * must mean "I looked and it is not there", never "I cannot tell" — an adapter
 * that cannot look does not implement this interface at all, so the reconciler
 * refuses instead of reading a guess as an answer.
 */
interface ReconcilesProviderCommands extends ProviderPort
{
    public function findCommand(string $idempotencyKey): ?CommandOutcome;
}
