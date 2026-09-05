<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesProviderCommands;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Enums\CommandFailureReason;
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

        // An answer about some other command is not an answer about this one.
        // Without this the identity guarantee is only as good as the adapter:
        // a wrong or hostile implementation could settle an unknown command
        // with a different command's success, which is exactly the duplicate
        // execution the key exists to prevent.
        if ($known !== null && $known->idempotencyKey !== $outcome->idempotencyKey) {
            throw new ProviderUnknownOutcomeException(
                providerId: $this->providerId($provider),
                operation: 'reconcile_command',
                message: 'The provider answered about a different command; the outcome stays unknown and must not be retried.',
                context: ['idempotency_key' => $outcome->idempotencyKey],
            );
        }

        // Absence is an answer here, not a shrug: the interface's contract is
        // that an adapter which cannot look does not implement it.
        return $known ?? CommandOutcome::notDelivered(
            $outcome->idempotencyKey,
            CommandFailureReason::AbsentAtProvider,
        );
    }

    private function providerId(object $provider): string
    {
        return method_exists($provider, 'descriptor') ? $provider->descriptor()->id : 'unknown';
    }
}
