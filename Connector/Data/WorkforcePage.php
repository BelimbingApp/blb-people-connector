<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforcePage
{
    /**
     * @param  list<WorkforceEmployee>  $employees
     * @param  list<WorkforceCompany>  $companies
     * @param  list<WorkforceOrganizationUnit>  $organizationUnits
     * @param  list<WorkforcePosition>  $positions
     */
    public function __construct(
        public array $employees,
        public \DateTimeImmutable $asOf,
        public ?string $nextPageCursor = null,
        public ?string $resumeCursor = null,
        public bool $complete = false,
        public array $companies = [],
        public array $organizationUnits = [],
        public array $positions = [],
    ) {
        foreach ($employees as $employee) {
            if (! $employee instanceof WorkforceEmployee) {
                throw new \InvalidArgumentException('Workforce pages accept only WorkforceEmployee values.');
            }
        }

        foreach ($companies as $company) {
            if (! $company instanceof WorkforceCompany) {
                throw new \InvalidArgumentException('Workforce pages accept only WorkforceCompany company values.');
            }
        }

        foreach ($organizationUnits as $organizationUnit) {
            if (! $organizationUnit instanceof WorkforceOrganizationUnit) {
                throw new \InvalidArgumentException('Workforce pages accept only WorkforceOrganizationUnit organization values.');
            }
        }

        foreach ($positions as $position) {
            if (! $position instanceof WorkforcePosition) {
                throw new \InvalidArgumentException('Workforce pages accept only WorkforcePosition position values.');
            }
        }

        if (! $complete && ($nextPageCursor === null || trim($nextPageCursor) === '')) {
            throw new \InvalidArgumentException('An incomplete workforce page must provide the next page cursor.');
        }

        if (! $complete && $resumeCursor !== null) {
            throw new \InvalidArgumentException('Only a complete workforce page can provide a durable resume cursor.');
        }

        if ($complete && $nextPageCursor !== null) {
            throw new \InvalidArgumentException('A complete workforce page cannot provide a next page cursor.');
        }

        if ($complete && ($resumeCursor === null || trim($resumeCursor) === '')) {
            throw new \InvalidArgumentException('A complete workforce page must provide a durable resume cursor.');
        }
    }
}
