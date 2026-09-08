<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportDryRun;
use App\Domains\PeopleConnector\Connector\Data\Hr2000ImportReconciliation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;

/**
 * Classifies an HR2000 dry run's typed records against one connection's
 * current employee projections, writing nothing (#298). Each record is looked
 * up by its EmpNo through the connection's active external identities; an
 * active projection the file does not name is missing_from_file, which implies
 * no deactivation until an approved import policy says so
 * (docs/providers/hr2000-file-exchange.md).
 *
 * Reads are scoped to the current tenant and to the one connection, so a
 * sibling connection's projections and another tenant's are neither compared
 * nor listed. A defective row has no typed record and so no EmpNo here: the
 * projection it names, if any, shows as missing_from_file until the row is
 * fixed.
 */
final class Hr2000ImportReconciler
{
    public const RECONCILE_CAPABILITY = 'people-connector.connection.manage';

    /** Projection column => the record field it is compared with, named the way the report names it. */
    private const FIELDS = [
        'display_name' => 'display_name',
        'employee_number' => 'employee_number',
        'email' => 'email',
        'company_entity_id' => 'company_reference',
        'organization_entity_id' => 'organization_reference',
        'position_entity_id' => 'position_reference',
        'manager_entity_id' => 'manager_reference',
        'effective_at' => 'effective_at',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
    ) {}

    public function reconcile(Actor $actor, ProviderConnection $connection, Hr2000ImportDryRun $run): Hr2000ImportReconciliation
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, self::RECONCILE_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId || (int) $connection->tenant_id !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'hr2000_reconcile', 'An HR2000 import reconciliation runs as an operator inside the connection\'s tenant.');
        }
        if ($connection->provider_id !== Hr2000Adapter::ID) {
            throw new InvalidProviderConfigurationException('An HR2000 import reconciles against an '.Hr2000Adapter::ID.' connection.');
        }

        // The connection's active identities: employees by EmpNo, and every
        // entity by id so a projection's references read back as external ids.
        $identities = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', (int) $connection->id)
            ->where('state', ExternalIdentity::STATE_ACTIVE)
            ->get(['workforce_entity_id', 'resource_type', 'external_id']);
        $externalIdByEntity = [];
        $employeeEntityByExternalId = [];
        foreach ($identities as $identity) {
            $externalIdByEntity[(int) $identity->workforce_entity_id] = (string) $identity->external_id;
            if ($identity->resource_type === WorkforceResourceType::Employee->value) {
                $employeeEntityByExternalId[(string) $identity->external_id] = (int) $identity->workforce_entity_id;
            }
        }

        $projections = $employeeEntityByExternalId === [] ? collect() : WorkforceEmployeeProjection::query()
            ->withoutCompanyScope('A dry run reconciles the whole file against every employee this connection projects; the company is part of the record being compared, and the connection is already tenant-scoped.')
            ->forTenant($tenantId)
            ->whereIn('workforce_entity_id', array_values($employeeEntityByExternalId))
            ->get()
            ->keyBy('workforce_entity_id');

        $classes = [];
        $named = [];
        foreach ($run->records as $record) {
            $employee = $record->employee;
            $empNo = $employee->reference->externalId;
            $named[$empNo] = true;
            $projection = $projections->get($employeeEntityByExternalId[$empNo] ?? null);
            if ($projection === null) {
                $classes[Hr2000ImportReconciliation::WOULD_CREATE][] = ['employee_number' => $empNo];
            } elseif (! $employee->active && $projection->active) {
                $classes[Hr2000ImportReconciliation::WOULD_DEACTIVATE][] = ['employee_number' => $empNo];
            } elseif ($employee->active && ! $projection->active) {
                $classes[Hr2000ImportReconciliation::WOULD_REACTIVATE][] = ['employee_number' => $empNo];
            } elseif (($fields = $this->changedFields($projection, $employee, $externalIdByEntity)) !== []) {
                $classes[Hr2000ImportReconciliation::WOULD_UPDATE][] = ['employee_number' => $empNo, 'fields' => $fields];
            } else {
                $classes[Hr2000ImportReconciliation::UNCHANGED][] = ['employee_number' => $empNo];
            }
        }

        $missing = [];
        foreach ($employeeEntityByExternalId as $empNo => $entityId) {
            $projection = $projections->get($entityId);
            if ($projection !== null && $projection->active && ! isset($named[$empNo])) {
                $missing[] = (string) $empNo;
            }
        }
        sort($missing, SORT_STRING);
        $classes[Hr2000ImportReconciliation::MISSING_FROM_FILE] = array_map(static fn (string $empNo): array => ['employee_number' => $empNo], $missing);

        return new Hr2000ImportReconciliation((int) $connection->id, $classes);
    }

    /**
     * @param  array<int, string>  $externalIdByEntity
     * @return list<string> report field names whose projected value differs from the record
     */
    private function changedFields(WorkforceEmployeeProjection $projection, WorkforceEmployee $employee, array $externalIdByEntity): array
    {
        $reference = static fn (?ExternalReference $reference): ?string => $reference?->externalId;
        $entity = static fn (mixed $id): ?string => $id === null ? null : ($externalIdByEntity[(int) $id] ?? null);
        $incoming = [
            'display_name' => $employee->displayName,
            'employee_number' => $employee->employeeNumber,
            'email' => $employee->email,
            'company_reference' => $reference($employee->companyReference),
            'organization_reference' => $reference($employee->organizationReference),
            'position_reference' => $reference($employee->positionReference),
            'manager_reference' => $reference($employee->managerReference),
            'effective_at' => $employee->effectiveAt->getTimestamp(),
        ];
        $current = [
            'display_name' => (string) $projection->display_name,
            'employee_number' => $projection->employee_number === null ? null : (string) $projection->employee_number,
            'email' => $projection->email === null ? null : (string) $projection->email,
            'company_reference' => $entity($projection->company_entity_id),
            'organization_reference' => $entity($projection->organization_entity_id),
            'position_reference' => $entity($projection->position_entity_id),
            'manager_reference' => $entity($projection->manager_entity_id),
            'effective_at' => $projection->effective_at->getTimestamp(),
        ];

        $changed = [];
        foreach (self::FIELDS as $field) {
            if ($current[$field] !== $incoming[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
