<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople\Services;

use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany as PeopleWorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceDeactivation as PeopleWorkforceDeactivation;
use App\Domains\People\Provider\Data\WorkforceEmployee as PeopleWorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit as PeopleWorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceUpsert as PeopleWorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

/**
 * Restates one published People record as its provider-neutral connector value.
 *
 * The two vocabularies are deliberately near-identical, so this stays a
 * rename rather than a reinterpretation. The two places they differ are the
 * places People has published nothing: a connector organization unit can name
 * a parent unit and a connector employee can name a position, and People
 * emits neither, so both arrive null rather than invented.
 */
final readonly class WorkforceRecordTranslator
{
    public function reference(PeopleExternalReference $reference): ExternalReference
    {
        return new ExternalReference(
            providerId: PeopleExternalReference::PROVIDER_ID,
            resourceType: WorkforceResourceType::from($reference->resourceType->value),
            externalId: $reference->externalId,
        );
    }

    public function company(PeopleWorkforceCompany $company): WorkforceCompany
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

    public function organizationUnit(PeopleWorkforceOrganizationUnit $unit): WorkforceOrganizationUnit
    {
        return new WorkforceOrganizationUnit(
            reference: $this->reference($unit->reference),
            companyReference: $this->reference($unit->companyReference),
            name: $unit->name,
            active: $unit->active,
            effectiveAt: $unit->effectiveAt,
            observedAt: $unit->observedAt,
            parentReference: null,
            code: $unit->code,
            kind: $unit->kind,
            sourceVersion: $unit->sourceVersion,
        );
    }

    public function employee(PeopleWorkforceEmployee $employee): WorkforceEmployee
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
            positionReference: null,
            managerReference: $this->optionalReference($employee->managerReference),
            departmentHeadReference: $this->optionalReference($employee->departmentHeadReference),
            sourceVersion: $employee->sourceVersion,
            userReferenceRevoked: $employee->userReferenceRevoked,
        );
    }

    public function change(PeopleWorkforceUpsert|PeopleWorkforceDeactivation $change): WorkforceUpsert|WorkforceDeactivation
    {
        if ($change instanceof PeopleWorkforceDeactivation) {
            return new WorkforceDeactivation(
                reference: $this->reference($change->reference),
                occurredAt: $change->occurredAt,
            );
        }

        return new WorkforceUpsert(
            record: match (true) {
                $change->record instanceof PeopleWorkforceCompany => $this->company($change->record),
                $change->record instanceof PeopleWorkforceOrganizationUnit => $this->organizationUnit($change->record),
                default => $this->employee($change->record),
            },
            occurredAt: $change->occurredAt,
        );
    }

    private function optionalReference(?PeopleExternalReference $reference): ?ExternalReference
    {
        return $reference === null ? null : $this->reference($reference);
    }
}
