<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;

final readonly class ReconciliationIssueDetails
{
    public function __construct(
        public ?string $field = null,
        public ?string $reasonCode = null,
        public ?int $expectedCount = null,
        public ?int $observedCount = null,
        public ?string $relatedExternalId = null,
        /**
         * A hex digest of a feed page that could not be applied, so an operator
         * can tell two parked pages apart and recognise the same one coming
         * back. Validated as a digest and nothing else: a hash field that
         * accepted arbitrary text would be the generic payload slot
         * docs/contracts/diagnostic-privacy.md says this DTO must not have.
         */
        public ?string $payloadHash = null,
    ) {
        foreach ([$field, $reasonCode] as $identifier) {
            if ($identifier !== null
                && (strlen($identifier) > 100
                    || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $identifier) !== 1)) {
                throw new InvalidReconciliationIssueException(
                    'Reconciliation detail identifiers require a stable lowercase value.',
                );
            }
        }

        foreach ([$expectedCount, $observedCount] as $count) {
            if ($count !== null && $count < 0) {
                throw new InvalidReconciliationIssueException('Reconciliation detail counts cannot be negative.');
            }
        }

        if ($payloadHash !== null && preg_match('/^[0-9a-f]{64}$/', $payloadHash) !== 1) {
            throw new InvalidReconciliationIssueException(
                'A reconciliation payload hash is a 64-character lowercase hex digest and never the payload itself.',
            );
        }

        if ($relatedExternalId !== null && (trim($relatedExternalId) === '' || strlen($relatedExternalId) > 512)) {
            throw new InvalidReconciliationIssueException('Related reconciliation external identifiers must be non-empty and cannot exceed 512 bytes.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field,
            'reason_code' => $this->reasonCode,
            'expected_count' => $this->expectedCount,
            'observed_count' => $this->observedCount,
            'related_external_id' => $this->relatedExternalId,
            'payload_hash' => $this->payloadHash,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
