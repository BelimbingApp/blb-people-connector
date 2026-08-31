<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final readonly class WorkforceOrganizationUnit
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $name,
        public bool $active,
        public \DateTimeImmutable $effectiveAt,
        public \DateTimeImmutable $observedAt,
        public ?ExternalReference $parentReference = null,
        public ?string $code = null,
        public ?string $kind = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::OrganizationUnit
            || $companyReference->resourceType !== WorkforceResourceType::Company
            || ($parentReference !== null && $parentReference->resourceType !== WorkforceResourceType::OrganizationUnit)) {
            throw new \InvalidArgumentException('Workforce organization units require organization, company, and optional parent-organization references.');
        }

        foreach ([$companyReference, $parentReference] as $relatedReference) {
            if ($relatedReference !== null && $relatedReference->providerId !== $reference->providerId) {
                throw new \InvalidArgumentException('Workforce organization-unit references cannot cross providers.');
            }
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce organization-unit names cannot be empty.');
        }
    }
}
