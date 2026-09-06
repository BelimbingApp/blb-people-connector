<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class RetentionPurgeResult
{
    /** @param array<string, int> $deleted keyed by table name */
    public function __construct(
        public string $runId,
        public int $tenantId,
        public \DateTimeImmutable $executedAt,
        public array $deleted,
    ) {}

    public function deletedFor(string $table): int
    {
        return $this->deleted[$table] ?? 0;
    }

    public function totalDeleted(): int
    {
        return array_sum($this->deleted);
    }
}
