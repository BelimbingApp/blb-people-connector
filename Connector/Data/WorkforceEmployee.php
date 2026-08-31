<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final readonly class WorkforceEmployee
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $displayName,
        public bool $active,
        public \DateTimeImmutable $effectiveAt,
        public \DateTimeImmutable $observedAt,
        public ?string $employeeNumber = null,
        public ?string $email = null,
        public ?ExternalReference $userReference = null,
        public ?ExternalReference $organizationReference = null,
        public ?ExternalReference $positionReference = null,
        public ?ExternalReference $managerReference = null,
        public ?ExternalReference $departmentHeadReference = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Employee
            || $companyReference->resourceType !== WorkforceResourceType::Company
            || ($userReference !== null && $userReference->resourceType !== WorkforceResourceType::User)
            || ($organizationReference !== null && $organizationReference->resourceType !== WorkforceResourceType::OrganizationUnit)
            || ($positionReference !== null && $positionReference->resourceType !== WorkforceResourceType::Position)
            || ($managerReference !== null && $managerReference->resourceType !== WorkforceResourceType::Employee)
            || ($departmentHeadReference !== null && $departmentHeadReference->resourceType !== WorkforceResourceType::Employee)) {
            throw new \InvalidArgumentException('Workforce employees contain a mismatched workforce reference type.');
        }

        foreach ([$companyReference, $userReference, $organizationReference, $positionReference, $managerReference, $departmentHeadReference] as $relatedReference) {
            if ($relatedReference !== null && $relatedReference->providerId !== $reference->providerId) {
                throw new \InvalidArgumentException('Workforce employee references cannot cross providers.');
            }
        }

        if (trim($displayName) === '') {
            throw new \InvalidArgumentException('Workforce employee display names cannot be empty.');
        }
    }
}
