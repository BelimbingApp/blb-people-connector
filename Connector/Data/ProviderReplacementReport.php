<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What one approved provider replacement did.
 *
 * Counted from the identities actually rebound, not from the mapping it was
 * handed: a replacement that was given ten lines and rebound ten identities
 * says ten, and there is no partial number to report because the operation is
 * all or nothing.
 */
final readonly class ProviderReplacementReport
{
    public function __construct(
        public int $fromConnectionId,
        public int $toConnectionId,
        public int $remapped,
        public string $reviewReference,
    ) {}
}
