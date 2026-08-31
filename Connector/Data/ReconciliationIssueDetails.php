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
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field,
            'reason_code' => $this->reasonCode,
            'expected_count' => $this->expectedCount,
            'observed_count' => $this->observedCount,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
