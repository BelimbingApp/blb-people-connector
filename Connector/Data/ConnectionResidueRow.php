<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What one table still holds for a retired connection.
 *
 * The count is how many rows still name the connection. The retention entry is
 * the domain's declared policy for that table (`days` or kept forever). A flag
 * is a decision still outstanding — revoke, resolve, remap or archive — not a
 * row that is merely past its retention window.
 */
final readonly class ConnectionResidueRow
{
    public function __construct(
        public string $table,
        public int $count,
        public ?int $retentionDays,
        public bool $flagged,
        public ?string $flagReason = null,
    ) {}

    public function retentionLabel(): string
    {
        return $this->retentionDays === null ? 'kept' : (string) $this->retentionDays;
    }
}
