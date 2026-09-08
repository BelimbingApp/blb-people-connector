<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceSubjectExportResult
{
    /** @param array<string, int> $counts */
    public function __construct(
        public string $packageId,
        public string $path,
        public string $sha256,
        public int $bytes,
        public array $counts,
    ) {}

    /** @return array{package_id: string, path: string, sha256: string, bytes: int, counts: array<string, int>} */
    public function toArray(): array
    {
        return [
            'package_id' => $this->packageId,
            'path' => $this->path,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'counts' => $this->counts,
        ];
    }
}
