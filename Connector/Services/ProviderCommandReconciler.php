<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesProviderCommands;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderUnknownOutcomeException;

/**
 * Turns an unknown command outcome into a settled one by asking the provider
 * what it already holds, so nothing is ever sent twice on the strength of a
 * timeout.
 *
 * The refusal is the point. An adapter that cannot reconcile leaves the outcome
 * unknown, and unknown must stay unknown: deciding "probably not delivered"
 * here would reintroduce the blind retry the whole contract exists to stop.
 */
final class ProviderCommandReconciler
{
    public function settle(CommandOutcome $outcome, object $provider): CommandOutcome
    {
        if (! $outcome->requiresReconciliation()) {
            return $outcome;
        }

        if (! $provider instanceof ReconcilesProviderCommands) {
            throw new ProviderUnknownOutcomeException(
                providerId: $this->providerId($provider),
                operation: 'reconcile_command',
                message: 'The outcome is unknown and this provider cannot say whether the command exists; it must not be retried.',
                context: ['idempotency_key' => $outcome->idempotencyKey],
            );
        }

        $known = $provider->findCommand($outcome->idempotencyKey);

        // Absence is an answer here, not a shrug: the interface's contract is
        // that an adapter which cannot look does not implement it.
        return $known ?? CommandOutcome::notDelivered(
            $outcome->idempotencyKey,
            'The provider holds no command under this key.',
        );
    }

    private function providerId(object $provider): string
    {
        return method_exists($provider, 'descriptor') ? $provider->descriptor()->id : 'unknown';
    }
}
