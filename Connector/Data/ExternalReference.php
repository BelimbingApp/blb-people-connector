<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final readonly class ExternalReference
{
    public function __construct(
        public string $providerId,
        public WorkforceResourceType $resourceType,
        public string $externalId,
    ) {
        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $providerId) !== 1) {
            throw new \InvalidArgumentException('External references require a stable lowercase provider ID.');
        }

        if (trim($externalId) === '') {
            throw new \InvalidArgumentException('External reference externalId cannot be empty.');
        }
    }
}
