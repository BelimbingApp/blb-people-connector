<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final readonly class WorkforceCompany
{
    public function __construct(
        public ExternalReference $reference,
        public string $name,
        public bool $active,
        public \DateTimeImmutable $observedAt,
        public ?string $code = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Company) {
            throw new \InvalidArgumentException('Workforce companies require a company reference.');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce company names cannot be empty.');
        }
    }
}
