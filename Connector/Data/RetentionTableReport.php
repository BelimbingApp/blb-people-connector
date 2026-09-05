<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What retention says about one connector-owned table, and how many rows are
 * already past it.
 *
 * A null window is "indefinite", and it is a declared policy rather than a
 * missing entry: an operator reading this report should be able to tell
 * "we keep these forever" apart from "nobody has decided yet".
 */
final readonly class RetentionTableReport
{
    public function __construct(
        public string $table,
        public ?int $days,
        public ?string $column,
        public int $expired,
    ) {}

    public function isIndefinite(): bool
    {
        return $this->days === null;
    }
}
