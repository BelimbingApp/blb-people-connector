<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFileImportResult
{
    /** @param list<array{row: int, code: string, detail: string}> $rejections */
    public function __construct(
        public int $accepted,
        public int $rejected,
        public array $rejections = [],
    ) {
        if ($accepted < 0 || $rejected < 0 || count($rejections) !== $rejected) {
            throw new \InvalidArgumentException('Provider file import counts must be non-negative and match rejection details.');
        }
    }
}
