<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\CommandResolution;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use Illuminate\Support\Facades\DB;

/**
 * Commits a reviewed identity decision and closes its queue item together.
 *
 * The identity store and issue store each use nested transactions/locks for
 * their own public contracts. This outer transaction supplies the missing
 * operation boundary: fresh queue evidence cannot leave a successfully
 * merged or remapped identity behind an unresolved issue.
 */
final class ReconciliationReviewService
{
    public function __construct(
        private ReconciliationIssueStore $issues,
        private TenantConnectionLocator $connections,
        private WorkforceIdentityStore $identities,
    ) {}

    public function applyMerge(
        int $connectionId,
        int $issueId,
        string $reviewReference,
        \DateTimeInterface $occurredAt,
    ): ReconciliationIssue {
        return DB::transaction(function () use ($connectionId, $issueId, $reviewReference, $occurredAt): ReconciliationIssue {
            $issue = $this->issues->requireOpenForConnection($connectionId, $issueId, lock: true);
            [$connection, $resourceType, $supersededExternalId, $survivingExternalId] = $this->mergeEvidence($connectionId, $issue);

            $this->identities->merge(
                $connectionId,
                new ExternalReference($connection->provider_id, $resourceType, $supersededExternalId),
                new ExternalReference($connection->provider_id, $resourceType, $survivingExternalId),
                $occurredAt,
                new WorkforceProvenance('reconciliation.review', $reviewReference),
            );

            return $this->issues->resolve($issueId, $occurredAt);
        });
    }

    /**
     * Record what an operator found out about a command the connector could not
     * settle, and close the issue.
     *
     * Deliberately writes nothing but the finding. The acceptance for #146 asks
     * that a resolution never triggers a retry by itself, and the reason is the
     * same one behind #138: an unknown outcome may not be resent on a guess, and
     * an operator clearing a queue is still a guess unless they checked. Any
     * resend is a separate, deliberate act.
     */
    public function confirmCommandOutcome(
        int $connectionId,
        int $issueId,
        CommandResolution $resolution,
        string $reviewReference,
        \DateTimeInterface $occurredAt,
    ): ReconciliationIssue {
        return DB::transaction(function () use ($connectionId, $issueId, $resolution, $reviewReference, $occurredAt): ReconciliationIssue {
            $issue = $this->issues->requireOpenForConnection($connectionId, $issueId, lock: true);

            if ($issue->kind !== UnknownOutcomeReporter::ISSUE_KIND) {
                throw new InvalidReconciliationIssueException(
                    'Only an unknown command outcome can be confirmed as delivered or not delivered.',
                );
            }

            if (trim($reviewReference) === '') {
                throw new InvalidReconciliationIssueException(
                    'Confirming a command outcome requires a review reference so the decision is attributable.',
                );
            }

            $issue->update([
                'details' => array_merge((array) $issue->details, ['reason_code' => $resolution->value]),
            ]);

            return $this->issues->resolve($issueId, $occurredAt);
        });
    }

    public function applyRemap(
        int $connectionId,
        int $issueId,
        string $replacementExternalId,
        string $reviewReference,
        \DateTimeInterface $occurredAt,
    ): ReconciliationIssue {
        return DB::transaction(function () use ($connectionId, $issueId, $replacementExternalId, $reviewReference, $occurredAt): ReconciliationIssue {
            $issue = $this->issues->requireOpenForConnection($connectionId, $issueId, lock: true);
            [$connection, $resourceType, $currentExternalId] = $this->referenceEvidence($connectionId, $issue);
            $replacementExternalId = trim($replacementExternalId);

            if ($replacementExternalId === '' || $replacementExternalId === $currentExternalId || strlen($replacementExternalId) > 512) {
                throw new InvalidReconciliationIssueException('A reviewed identity remap requires a distinct, valid replacement external identifier.');
            }

            $this->identities->remap(
                $connectionId,
                new ExternalReference($connection->provider_id, $resourceType, $currentExternalId),
                new ExternalReference($connection->provider_id, $resourceType, $replacementExternalId),
                $occurredAt,
                new WorkforceProvenance('reconciliation.review', $reviewReference),
            );

            return $this->issues->resolve($issueId, $occurredAt);
        });
    }

    /** @return array{0: object, 1: WorkforceResourceType, 2: string, 3: string} */
    private function mergeEvidence(int $connectionId, ReconciliationIssue $issue): array
    {
        if ($issue->kind !== 'sync_merge_requested') {
            throw new InvalidReconciliationIssueException('Only queued merge issues can apply a reviewed merge.');
        }

        [$connection, $resourceType, $supersededExternalId] = $this->referenceEvidence($connectionId, $issue);
        $survivingExternalId = $issue->details['related_external_id'] ?? null;

        if (! is_string($survivingExternalId)
            || trim($survivingExternalId) === ''
            || strlen($survivingExternalId) > 512
            || $survivingExternalId === $supersededExternalId) {
            throw new InvalidReconciliationIssueException('A queued merge requires two distinct valid external identifiers.');
        }

        return [$connection, $resourceType, $supersededExternalId, $survivingExternalId];
    }

    /** @return array{0: object, 1: WorkforceResourceType, 2: string} */
    private function referenceEvidence(int $connectionId, ReconciliationIssue $issue): array
    {
        $resourceType = WorkforceResourceType::tryFrom((string) $issue->resource_type);
        $externalId = $issue->external_id;

        if ($resourceType === null || ! is_string($externalId) || trim($externalId) === '' || strlen($externalId) > 512) {
            throw new InvalidReconciliationIssueException('The reconciliation issue does not identify a valid external workforce record.');
        }

        return [$this->connections->get($connectionId, lock: true), $resourceType, $externalId];
    }
}
