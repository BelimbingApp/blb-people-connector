<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Data\CommandRetryDecision;

/**
 * Bounds safe retries after reconciliation proves a command was absent.
 *
 * An attempt limit is a safety boundary, not a delivery guarantee: once the
 * limit is reached the idempotency key is parked for an operator rather than
 * sent again without new evidence.
 */
final readonly class ProviderCommandRetryPolicy
{
    public function __construct(private UnknownOutcomeReporter $unknownOutcomes) {}

    public function decide(int $connectionId, CommandOutcome $outcome, int $attempt): CommandRetryDecision
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('A command retry attempt must be at least one.');
        }

        $maxAttempts = config('people-connector.command_reconciliation.max_attempts', 3);
        $backoffSeconds = config('people-connector.command_reconciliation.backoff_seconds', 60);

        if (! is_int($maxAttempts) || $maxAttempts < 1) {
            throw new \InvalidArgumentException('people-connector.command_reconciliation.max_attempts must be a positive integer.');
        }

        if (! is_int($backoffSeconds) || $backoffSeconds < 0) {
            throw new \InvalidArgumentException('people-connector.command_reconciliation.backoff_seconds must be a non-negative integer.');
        }

        if (! $outcome->mayRetry()) {
            return new CommandRetryDecision(false, $attempt, 0);
        }

        if ($attempt < $maxAttempts) {
            return new CommandRetryDecision(true, $attempt + 1, $backoffSeconds);
        }

        $issue = $this->unknownOutcomes->record(
            $connectionId,
            CommandOutcome::unknown($outcome->idempotencyKey),
        );

        if ($issue === null) {
            throw new \LogicException('An exhausted command retry must create an operator reconciliation issue.');
        }

        return new CommandRetryDecision(false, $attempt, 0, $issue);
    }
}
