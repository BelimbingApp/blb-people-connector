<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final readonly class WorkforcePosition
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $name,
        public bool $active,
        public \DateTimeImmutable $effectiveAt,
        public \DateTimeImmutable $observedAt,
        public ?ExternalReference $organizationReference = null,
        public ?string $code = null,
        public ?string $tier = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Position
            || $companyReference->resourceType !== WorkforceResourceType::Company
            || ($organizationReference !== null && $organizationReference->resourceType !== WorkforceResourceType::OrganizationUnit)) {
            throw new \InvalidArgumentException('Workforce positions require position, company, and optional organization references.');
        }

        foreach ([$companyReference, $organizationReference] as $relatedReference) {
            if ($relatedReference !== null && $relatedReference->providerId !== $reference->providerId) {
                throw new \InvalidArgumentException('Workforce position references cannot cross providers.');
            }
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce position names cannot be empty.');
        }
    }
}
