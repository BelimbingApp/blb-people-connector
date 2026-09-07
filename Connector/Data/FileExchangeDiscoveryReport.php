<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What one discovery pass over a connection's inbound directory found (#297).
 * Each row names the file, its hash, what happened to it and the schema the
 * header matched. The directory itself is not here: it is where the bytes
 * live, not what they are, and this report lands in a terminal and an audit.
 */
final readonly class FileExchangeDiscoveryReport
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_ALREADY_RECORDED = 'already_recorded';

    public const STATUS_REFUSED = 'refused';

    /** @param list<array{file: string, sha256: ?string, status: string, schema: ?string, reason: ?string}> $files */
    public function __construct(
        public int $connectionId,
        public array $files,
    ) {}

    public function count(string $status): int
    {
        return count(array_filter($this->files, static fn (array $row): bool => $row['status'] === $status));
    }

    /** True when a path escaped the root or a file could not be read: the run exits non-zero. */
    public function refused(): bool
    {
        return $this->count(self::STATUS_REFUSED) > 0;
    }

    /** @return array{recorded: int, already_recorded: int, refused: int, schema_detected: int} */
    public function counts(): array
    {
        return [
            'recorded' => $this->count(self::STATUS_RECORDED),
            'already_recorded' => $this->count(self::STATUS_ALREADY_RECORDED),
            'refused' => $this->count(self::STATUS_REFUSED),
            'schema_detected' => count(array_filter($this->files, static fn (array $row): bool => $row['schema'] !== null)),
        ];
    }

    /** @return array{connection_id: int, counts: array<string, int>, files: list<array<string, ?string>>} */
    public function toArray(): array
    {
        return ['connection_id' => $this->connectionId, 'counts' => $this->counts(), 'files' => $this->files];
    }
}
