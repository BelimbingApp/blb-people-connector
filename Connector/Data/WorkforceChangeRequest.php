<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceChangeRequest
{
    public function __construct(
        public string $resumeCursor,
        public ?string $pageCursor = null,
        public int $limit = 250,
    ) {
        if (trim($resumeCursor) === '') {
            throw new \InvalidArgumentException('Incremental workforce reads require a resume cursor.');
        }

        if ($pageCursor !== null && trim($pageCursor) === '') {
            throw new \InvalidArgumentException('Incremental workforce page cursors cannot be empty.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Workforce change limit must be between 1 and 1000.');
        }
    }
}
