<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * How one provider connection is doing right now, as an operator would ask it:
 * is it switched on, and can what it wrote be relied on.
 */
final readonly class ConnectorConnectionHealth
{
    public function __construct(
        public int $connectionId,
        public string $scopeKey,
        public string $status,
        public bool $stale,
        public ?string $staleReason,
        public ?int $ageMinutes,
    ) {}
}
