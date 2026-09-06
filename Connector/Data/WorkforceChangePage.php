<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Support\WorkforcePageChecksum;

/**
 * `checksum`, when an adapter declares it, is WorkforcePageChecksum::of() over
 * this page's records; the sync runner refuses the page before projection
 * when the content does not fingerprint to it (#204).
 */
final readonly class WorkforceChangePage
{
    /** @param list<WorkforceUpsert|WorkforceDeactivation|WorkforceMerge> $changes */
    public function __construct(
        public array $changes,
        public \DateTimeImmutable $asOf,
        public ?string $nextPageCursor = null,
        public ?string $resumeCursor = null,
        public bool $complete = false,
        public ?string $checksum = null,
    ) {
        if ($checksum !== null && ! WorkforcePageChecksum::isWellFormed($checksum)) {
            throw new \InvalidArgumentException('A declared workforce change page checksum must be a lowercase hex SHA-256.');
        }
        foreach ($changes as $change) {
            if (! $change instanceof WorkforceUpsert
                && ! $change instanceof WorkforceDeactivation
                && ! $change instanceof WorkforceMerge) {
                throw new \InvalidArgumentException('Workforce change pages accept only typed workforce changes.');
            }
        }

        if (! $complete && ($nextPageCursor === null || trim($nextPageCursor) === '')) {
            throw new \InvalidArgumentException('An incomplete workforce change page must provide the next page cursor.');
        }

        if (! $complete && $resumeCursor !== null) {
            throw new \InvalidArgumentException('Only a complete workforce change page can advance the durable resume cursor.');
        }

        if ($complete && $nextPageCursor !== null) {
            throw new \InvalidArgumentException('A complete workforce change page cannot provide a next page cursor.');
        }

        if ($complete && ($resumeCursor === null || trim($resumeCursor) === '')) {
            throw new \InvalidArgumentException('A complete workforce change page must advance the durable resume cursor.');
        }
    }
}
