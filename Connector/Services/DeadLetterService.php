<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use Illuminate\Support\Facades\DB;

/**
 * The operator's way back for a page the connector gave up on.
 *
 * Re-queueing says "this is worth another try": it closes the parked issue and
 * clears the failure count so the next attempt starts from zero rather than
 * from the limit. It deliberately does not re-read the page itself — the
 * checkpoint has already moved past it, and reading an older cursor again is
 * exactly what WorkforceSyncRunner::replay does. Duplicating that here would be
 * a second, subtly different rewind for someone to get wrong.
 */
final class DeadLetterService
{
    public function __construct(
        private readonly ReconciliationIssueStore $issues,
        private readonly SyncCheckpointStore $checkpoints,
    ) {}

    public function requeue(
        int $connectionId,
        int $issueId,
        string $reviewReference,
        ?\DateTimeInterface $occurredAt = null,
    ): ReconciliationIssue {
        if (trim($reviewReference) === '') {
            throw new InvalidReconciliationIssueException(
                'Re-queueing a parked page is an operator decision and requires a review reference.',
            );
        }

        return DB::transaction(function () use ($connectionId, $issueId, $occurredAt): ReconciliationIssue {
            try {
                $issue = $this->issues->requireOpenForConnection($connectionId, $issueId, lock: true);
            } catch (ConnectorRecordNotFoundException $missing) {
                throw $missing;
            }

            if ($issue->kind !== WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER) {
                throw new InvalidReconciliationIssueException(
                    'Only a parked feed page can be re-queued.',
                );
            }

            $resolved = $this->issues->resolve($issueId, $occurredAt ?? now());
            $this->checkpoints->clearFailedAttempts($connectionId, WorkforceFreshnessPolicy::stream());

            return $resolved;
        });
    }
}
