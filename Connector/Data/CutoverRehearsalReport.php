<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What a proposed provider cutover would break, found without breaking it.
 *
 * The three blockers are separate facts with separate remedies, not three views
 * of one problem: map the missing identities, run the new provider once, or
 * answer the outstanding questions. An operator reading a single "not ready"
 * would learn nothing about which of those to go and do.
 */
final readonly class CutoverRehearsalReport
{
    public function __construct(
        public int $fromConnectionId,
        public int $toConnectionId,
        /** Identities the source has that the target connection cannot answer for. */
        public int $unmappedIdentities,
        /** The target has never completed a pass, or its watermark is past the maximum age. */
        public bool $targetStale,
        public ?string $targetStaleReason,
        /** Open reconciliation issues on either connection. */
        public int $openIssues,
    ) {}

    public function blocked(): bool
    {
        return $this->unmappedIdentities > 0 || $this->targetStale || $this->openIssues > 0;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return array_values(array_filter([
            $this->unmappedIdentities > 0 ? "{$this->unmappedIdentities} identity/identities have no counterpart on the target connection" : null,
            $this->targetStale ? "the target connection is stale ({$this->targetStaleReason})" : null,
            $this->openIssues > 0 ? "{$this->openIssues} reconciliation issue(s) are still open" : null,
        ]));
    }
}
