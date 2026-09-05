<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;

/**
 * Puts a command that reconciliation could not settle in front of an operator.
 *
 * An unknown outcome is not a failure the connector may clear on its own — that
 * is the whole point of #138 — so the only remaining move is to hand it to
 * someone who can ask the provider directly. The issue is keyed by the
 * idempotency key so the operator asks about the same command the connector
 * asked about, and reporting the same command twice keeps one open issue rather
 * than growing a queue of duplicates for one stuck write.
 *
 * The adapter's answer is recorded as a CommandFailureReason code and never as
 * adapter text, per docs/contracts/diagnostic-privacy.md.
 */
final class UnknownOutcomeReporter
{
    public const ISSUE_KIND = 'sync_unknown_outcome';

    public function __construct(private readonly ReconciliationIssueStore $issues) {}

    public function record(int $connectionId, CommandOutcome $outcome): ?ReconciliationIssue
    {
        if (! $outcome->requiresReconciliation()) {
            return null;
        }

        return $this->issues->report(
            $connectionId,
            $outcome->idempotencyKey,
            self::ISSUE_KIND,
            new ReconciliationIssueDetails(reasonCode: $outcome->reason?->value),
            severity: 'warning',
        );
    }
}
