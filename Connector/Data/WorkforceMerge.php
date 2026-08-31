<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceMerge
{
    public function __construct(
        public ExternalReference $supersededReference,
        public ExternalReference $survivingReference,
        public \DateTimeImmutable $occurredAt,
    ) {
        if ($supersededReference == $survivingReference) {
            throw new \InvalidArgumentException('A workforce merge requires two distinct references.');
        }

        if ($supersededReference->providerId !== $survivingReference->providerId
            || $supersededReference->resourceType !== $survivingReference->resourceType) {
            throw new \InvalidArgumentException('A workforce merge cannot cross providers or resource types.');
        }
    }
}
