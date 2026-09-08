<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ScratchRestoreResult
{
    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {}
}
