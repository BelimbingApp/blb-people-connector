<?php

namespace App\Domains\PeopleConnector\NativePeople;

use App\Domains\People\Provider\Data\ExternalReference as NativeReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage as NativeBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceCompany as NativeCompany;
use App\Domains\People\Provider\Data\WorkforceDeactivation as NativeDeactivation;
use App\Domains\People\Provider\Data\WorkforceEmployee as NativeEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit as NativeOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceUpsert as NativeUpsert;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

final class NativePeopleWorkforceMapper
{
    public function page(NativeBootstrapPage $page): WorkforcePage
    {
        return new WorkforcePage(
            employees: array_map($this->employee(...), $page->employees),
            asOf: $page->asOf,
            nextPageCursor: $page->nextPageCursor,
            resumeCursor: $page->resumeCursor,
            complete: $page->complete,
            companies: array_map($this->company(...), $page->companies),
            organizationUnits: array_map($this->organizationUnit(...), $page->organizationUnits),
        );
    }

    public function upsert(NativeUpsert $change): WorkforceUpsert
    {
        $record = match (true) {
            $change->record instanceof NativeCompany => $this->company($change->record),
            $change->record instanceof NativeOrganizationUnit => $this->organizationUnit($change->record),
            $change->record instanceof NativeEmployee => $this->employee($change->record),
        };

        return new WorkforceUpsert($record, $change->occurredAt);
    }

    public function deactivation(NativeDeactivation $change): WorkforceDeactivation
    {
        return new WorkforceDeactivation(
            $this->reference($change->reference),
            $change->occurredAt,
        );
    }

    private function company(NativeCompany $company): WorkforceCompany
    {
        return new WorkforceCompany(
            reference: $this->reference($company->reference),
            name: $company->name,
            active: $company->active,
            observedAt: $company->observedAt,
            code: $company->code,
            sourceVersion: $company->sourceVersion,
        );
    }

    private function organizationUnit(NativeOrganizationUnit $unit): WorkforceOrganizationUnit
    {
        return new WorkforceOrganizationUnit(
            reference: $this->reference($unit->reference),
            companyReference: $this->reference($unit->companyReference),
            name: $unit->name,
            active: $unit->active,
            effectiveAt: $unit->effectiveAt,
            observedAt: $unit->observedAt,
            code: $unit->code,
            kind: $unit->kind,
            sourceVersion: $unit->sourceVersion,
        );
    }

    private function employee(NativeEmployee $employee): WorkforceEmployee
    {
        return new WorkforceEmployee(
            reference: $this->reference($employee->reference),
            companyReference: $this->reference($employee->companyReference),
            displayName: $employee->displayName,
            active: $employee->active,
            effectiveAt: $employee->effectiveAt,
            observedAt: $employee->observedAt,
            employeeNumber: $employee->employeeNumber,
            email: $employee->email,
            userReference: $this->optionalReference($employee->userReference),
            organizationReference: $this->optionalReference($employee->organizationReference),
            managerReference: $this->optionalReference($employee->managerReference),
            departmentHeadReference: $this->optionalReference($employee->departmentHeadReference),
            sourceVersion: $employee->sourceVersion,
            userReferenceRevoked: $employee->userReferenceRevoked,
        );
    }

    private function optionalReference(?NativeReference $reference): ?ExternalReference
    {
        return $reference === null ? null : $this->reference($reference);
    }

    private function reference(NativeReference $reference): ExternalReference
    {
        return new ExternalReference(
            providerId: NativePeopleAdapter::ID,
            resourceType: WorkforceResourceType::from($reference->resourceType->value),
            externalId: $reference->externalId,
        );
    }
}
