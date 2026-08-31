<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforcePageRequest
{
    public function __construct(
        public ?string $pageCursor = null,
        public int $limit = 250,
    ) {
        if ($pageCursor !== null && trim($pageCursor) === '') {
            throw new \InvalidArgumentException('Workforce page cursors cannot be empty.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Workforce page limit must be between 1 and 1000.');
        }
    }
}
