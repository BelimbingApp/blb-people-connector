<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What a retention review found for one tenant, at one instant.
 *
 * It reports and stops. Nothing here deletes, and nothing here is a promise
 * that deleting would be safe — a row past retention may still be referenced,
 * and working that out is the purge's job, not the report's.
 */
final readonly class RetentionReport
{
    /** @param array<string, RetentionTableReport> $tables keyed by table name */
    public function __construct(
        public int $tenantId,
        public \DateTimeImmutable $reviewedAt,
        public array $tables,
    ) {}

    public function expiredFor(string $table): int
    {
        return $this->tables[$table]?->expired ?? 0;
    }

    public function isIndefinite(string $table): bool
    {
        return $this->tables[$table]?->isIndefinite() ?? false;
    }

    public function totalExpired(): int
    {
        return array_sum(array_map(static fn (RetentionTableReport $t): int => $t->expired, $this->tables));
    }
}
