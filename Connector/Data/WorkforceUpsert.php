<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceUpsert
{
    public function __construct(
        public WorkforceCompany|WorkforceOrganizationUnit|WorkforcePosition|WorkforceEmployee $record,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
