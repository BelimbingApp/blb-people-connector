<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFileImportResult
{
    /** @param list<array{row: int, code: string, detail: string}> $rejections */
    public function __construct(
        public ProviderFile $file,
        public ProviderFileInspection $inspection,
        public int $accepted,
        public int $rejected,
        public array $rejections = [],
    ) {
        if (! $inspection->accepted || ! hash_equals($file->sha256, $inspection->sha256)) {
            throw new \InvalidArgumentException('Provider file imports require an accepted inspection of the exact file hash.');
        }

        if ($accepted < 0 || $rejected < 0 || count($rejections) !== $rejected) {
            throw new \InvalidArgumentException('Provider file import counts must be non-negative and match rejection details.');
        }
    }
}
