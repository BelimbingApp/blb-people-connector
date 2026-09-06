<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;

final readonly class CommandRetryDecision
{
    public function __construct(
        public bool $retry,
        public int $nextAttempt,
        public int $backoffSeconds,
        public ?ReconciliationIssue $issue = null,
    ) {}

    public function isParked(): bool
    {
        return $this->issue !== null;
    }
}
