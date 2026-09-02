<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceDeactivation
{
    public function __construct(
        public ExternalReference $reference,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
