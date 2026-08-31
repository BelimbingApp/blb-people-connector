<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidWorkforceProvenanceException;

final readonly class WorkforceProvenance
{
    public function __construct(
        public string $source,
        public ?string $reviewReference = null,
        public ?string $correlationReference = null,
    ) {
        if (strlen($source) > 100
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $source) !== 1) {
            throw new InvalidWorkforceProvenanceException(
                'Workforce provenance sources require a stable lowercase identifier.',
            );
        }

        foreach ([$reviewReference, $correlationReference] as $reference) {
            if ($reference !== null
                && (strlen($reference) > 191
                    || preg_match('/^[A-Za-z0-9]+(?:[._:\/-][A-Za-z0-9]+)*$/', $reference) !== 1)) {
                throw new InvalidWorkforceProvenanceException(
                    'Workforce provenance references must be opaque non-secret identifiers.',
                );
            }
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'source' => $this->source,
            'review_reference' => $this->reviewReference,
            'correlation_reference' => $this->correlationReference,
        ], static fn (?string $value): bool => $value !== null);
    }
}
