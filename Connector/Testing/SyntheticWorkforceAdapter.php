<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

/**
 * An in-memory provider that emits one company, M organisation units and N
 * employees with deterministic ids (#254). Nothing about it is random, so two
 * bootstraps of the same shape are byte-identical and the second is the
 * idempotence probe the bench reports on. It is never registered with the
 * ProviderRegistry: the bench hands it straight to the runner.
 */
final class SyntheticWorkforceAdapter implements BootstrapsWorkforce, ProviderAdapter, ResolvesProviderPorts
{
    public function __construct(
        private readonly string $providerId,
        private readonly int $employees,
        private readonly int $units,
        private readonly \DateTimeImmutable $observedAt,
    ) {
        if ($employees < 1 || $units < 1) {
            throw new \InvalidArgumentException('A synthetic workforce needs at least one employee and one unit.');
        }
    }

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor($this->providerId, 'Synthetic Bench Workforce', '1.0.0', '1.0.0');
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([
            new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
            ]),
        ]);
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(ProviderHealthState::Healthy, $this->observedAt);
    }

    public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
    {
        return $this instanceof $contract ? $this : null;
    }

    /**
     * Records are one flat sequence — company, units, employees — cut into
     * pages of the requested size; the cursor is the offset of the next page.
     */
    public function bootstrap(WorkforcePageRequest $request): WorkforcePage
    {
        $total = 1 + $this->units + $this->employees;
        $offset = $request->pageCursor === null ? 0 : (int) $request->pageCursor;
        $end = min($total, $offset + $request->limit);
        $companies = $units = $employees = [];

        for ($i = $offset; $i < $end; $i++) {
            if ($i === 0) {
                $companies[] = new WorkforceCompany($this->ref(WorkforceResourceType::Company, 'bench-co'), 'Bench Company', true, $this->observedAt, code: 'BENCH');
            } elseif ($i <= $this->units) {
                $units[] = $this->unit($i);
            } else {
                $employees[] = $this->employee($i - $this->units);
            }
        }

        return $end >= $total
            ? new WorkforcePage($employees, $this->observedAt, resumeCursor: 'bench:complete', complete: true, companies: $companies, organizationUnits: $units)
            : new WorkforcePage($employees, $this->observedAt, nextPageCursor: (string) $end, companies: $companies, organizationUnits: $units);
    }

    private function unit(int $n): WorkforceOrganizationUnit
    {
        return new WorkforceOrganizationUnit(
            $this->ref(WorkforceResourceType::OrganizationUnit, sprintf('bench-unit-%04d', $n)),
            $this->ref(WorkforceResourceType::Company, 'bench-co'),
            sprintf('Bench Unit %04d', $n),
            true,
            $this->observedAt,
            $this->observedAt,
            code: sprintf('BU%04d', $n),
            kind: 'department',
        );
    }

    private function employee(int $n): WorkforceEmployee
    {
        return new WorkforceEmployee(
            $this->ref(WorkforceResourceType::Employee, sprintf('bench-emp-%06d', $n)),
            $this->ref(WorkforceResourceType::Company, 'bench-co'),
            sprintf('Bench Employee %06d', $n),
            true,
            $this->observedAt,
            $this->observedAt,
            employeeNumber: sprintf('BE%06d', $n),
            email: sprintf('bench-%06d@bench.invalid', $n),
            organizationReference: $this->ref(WorkforceResourceType::OrganizationUnit, sprintf('bench-unit-%04d', 1 + (($n - 1) % $this->units))),
        );
    }

    private function ref(WorkforceResourceType $type, string $id): ExternalReference
    {
        return new ExternalReference($this->providerId, $type, $id);
    }
}
