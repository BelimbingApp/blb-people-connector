<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ReconciliationReport
{
    /** @param list<array{code: string, reference: string, detail: string}> $differences */
    public function __construct(
        public \DateTimeImmutable $asOf,
        public array $differences,
    ) {}
}
