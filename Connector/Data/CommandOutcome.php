<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\CommandFailureReason;
use App\Domains\PeopleConnector\Connector\Enums\CommandOutcomeState;

/**
 * The result of one attempt to send a command to a provider.
 *
 * The idempotency key is required on every outcome, including the ones that
 * look final: it is what reconciliation asks the provider about later, and an
 * outcome that cannot be reconciled is worse than no outcome at all.
 */
final readonly class CommandOutcome
{
    private function __construct(
        public CommandOutcomeState $state,
        public string $idempotencyKey,
        public ?string $providerReference = null,
        public ?CommandFailureReason $reason = null,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('A command outcome requires the idempotency key reconciliation will ask about.');
        }
    }

    public static function deliveredAccepted(string $idempotencyKey, ?string $providerReference = null): self
    {
        return new self(CommandOutcomeState::DeliveredAccepted, $idempotencyKey, $providerReference);
    }

    public static function deliveredRejected(string $idempotencyKey): self
    {
        return new self(CommandOutcomeState::DeliveredRejected, $idempotencyKey, reason: CommandFailureReason::ProviderRefused);
    }

    public static function notDelivered(string $idempotencyKey, CommandFailureReason $reason = CommandFailureReason::NotSent): self
    {
        return new self(CommandOutcomeState::NotDelivered, $idempotencyKey, reason: $reason);
    }

    public static function unknown(string $idempotencyKey): self
    {
        return new self(CommandOutcomeState::Unknown, $idempotencyKey, reason: CommandFailureReason::AnswerLost);
    }

    public function isSettled(): bool
    {
        return $this->state->isSettled();
    }

    public function mayRetry(): bool
    {
        return $this->state->mayRetry();
    }

    /** An unsettled outcome must be resolved against the provider before anything else happens. */
    public function requiresReconciliation(): bool
    {
        return ! $this->state->isSettled();
    }
}
