<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * One line of an operator-approved provider replacement: the reference the old
 * connection knew a workforce record by, and the reference the new connection
 * knows the same record by.
 *
 * The two references belong to different providers by definition — that is what
 * a replacement is — so nothing here may assume a shared provider id. What must
 * hold is the resource type: an employee is replaced by an employee.
 */
final readonly class ProviderIdentityMapping
{
    public function __construct(
        public ExternalReference $from,
        public ExternalReference $to,
    ) {
        if ($from->resourceType !== $to->resourceType) {
            throw new \InvalidArgumentException('A provider identity mapping cannot change the workforce resource type.');
        }

        if ($from->providerId === $to->providerId && $from->externalId === $to->externalId) {
            throw new \InvalidArgumentException('A provider identity mapping requires two distinct references.');
        }
    }
}
