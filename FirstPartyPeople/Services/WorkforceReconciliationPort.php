<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Contracts\ReadsWorkforcePositions;
use App\Domains\People\Provider\Data\WorkforceCompany as PeopleWorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee as PeopleWorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit as PeopleWorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforcePosition as PeopleWorkforcePosition;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationReport;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Compares People's published directory (and positions) with the connector
 * projections for one authorized connection scope. Reports drift; never writes.
 */
final readonly class WorkforceReconciliationPort implements ReconcilesWorkforce
{
    public function __construct(
        private ReadsWorkforceDirectory $directory,
        private ReadsWorkforcePositions $positions,
        private TenantContext $tenantContext,
        private ProviderConnectionStore $connections,
        private ProviderPortAuthorization $authorization,
    ) {}

    public function reconcile(): ReconciliationReport
    {
        $asOf = new DateTimeImmutable(now()->toISOString());

        // Conformance probes carry synthetic evidence (tenant 0 / scope
        // "conformance") and only need reconcile() not to throw.
        if ($this->authorization->scopeKey === 'conformance') {
            return new ReconciliationReport($asOf, []);
        }

        $tenantId = $this->tenantContext->requireTenantId();

        if ($this->authorization->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: FirstPartyPeopleAdapter::ID,
                operation: 'reconcile_workforce',
                message: 'Workforce reconciliation requires the authorizing tenant to still be the current tenant.',
            );
        }

        $scope = $this->scopeFromAuthorization();
        $connection = $this->connections->active($scope);

        if ($connection === null
            || (string) $connection->provider_id !== FirstPartyPeopleAdapter::ID
            || (string) $connection->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new ProviderAuthorizationException(
                providerId: FirstPartyPeopleAdapter::ID,
                operation: 'reconcile_workforce',
                message: 'Workforce reconciliation requires the active first-party connection for the authorized scope.',
            );
        }

        $differences = [];

        foreach ($this->companyStableIds($scope, $tenantId) as $companyStableId) {
            foreach ($this->diffCompany((int) $connection->id, $tenantId, $companyStableId) as $difference) {
                $differences[] = $difference;
            }
        }

        return new ReconciliationReport($asOf, $differences);
    }

    private function scopeFromAuthorization(): ProviderScope
    {
        $key = $this->authorization->scopeKey;

        if ($key === 'tenant') {
            return ProviderScope::tenant();
        }

        if (preg_match('/^company:(\d+)$/', $key, $matches) === 1) {
            return ProviderScope::company((int) $matches[1]);
        }

        throw new ProviderAuthorizationException(
            providerId: FirstPartyPeopleAdapter::ID,
            operation: 'reconcile_workforce',
            message: 'Workforce reconciliation received authorization for an unrecognised scope.',
        );
    }

    /**
     * @return list<string>
     */
    private function companyStableIds(ProviderScope $scope, int $tenantId): array
    {
        if ($scope->companyId !== null) {
            return [(string) $scope->companyId];
        }

        // People's directory has no list-companies call; the same Core Company
        // rows NativeWorkforceDirectory::findCompany reads are the companies
        // a tenant-scoped connection is allowed to see.
        return Company::query()
            ->forTenant($tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @return list<array{code: string, reference: string, detail: string}>
     */
    private function diffCompany(int $connectionId, int $tenantId, string $companyStableId): array
    {
        $provider = $this->providerRecords($companyStableId);
        $projected = $this->projectedRecords($connectionId, $tenantId, $companyStableId);

        $differences = [];
        $keys = array_unique([...array_keys($provider), ...array_keys($projected)]);
        sort($keys);

        foreach ($keys as $key) {
            $fromProvider = $provider[$key] ?? null;
            $fromProjection = $projected[$key] ?? null;

            if ($fromProvider === null) {
                $differences[] = [
                    'code' => 'missing_in_provider',
                    'reference' => $fromProjection['reference'],
                    'detail' => $fromProjection['resource'],
                ];

                continue;
            }

            if ($fromProjection === null) {
                $differences[] = [
                    'code' => 'missing_in_projection',
                    'reference' => $fromProvider['reference'],
                    'detail' => $fromProvider['resource'],
                ];

                continue;
            }

            if ($fromProvider['active'] !== $fromProjection['active']) {
                $differences[] = [
                    'code' => 'active_flag_mismatch',
                    'reference' => $fromProvider['reference'],
                    'detail' => $fromProvider['resource'],
                ];
            }

            if ($fromProvider['source_version'] !== null
                && $fromProjection['source_version'] !== null
                && (string) $fromProvider['source_version'] !== (string) $fromProjection['source_version']) {
                $differences[] = [
                    'code' => 'stale_source_version',
                    'reference' => $fromProvider['reference'],
                    'detail' => $fromProvider['resource'],
                ];
            }
        }

        return $differences;
    }

    /**
     * @return array<string, array{reference: string, resource: string, active: bool, source_version: ?string}>
     */
    private function providerRecords(string $companyStableId): array
    {
        $records = [];

        $company = $this->directory->company($companyStableId);
        if ($company instanceof PeopleWorkforceCompany) {
            $records[$this->key(WorkforceResourceType::Company, $company->reference->externalId)] = [
                'reference' => $company->reference->externalId,
                'resource' => WorkforceResourceType::Company->value,
                'active' => $company->active,
                'source_version' => $company->sourceVersion,
            ];
        }

        foreach ($this->directory->organizationUnits($companyStableId) as $unit) {
            /** @var PeopleWorkforceOrganizationUnit $unit */
            $records[$this->key(WorkforceResourceType::OrganizationUnit, $unit->reference->externalId)] = [
                'reference' => $unit->reference->externalId,
                'resource' => WorkforceResourceType::OrganizationUnit->value,
                'active' => $unit->active,
                'source_version' => $unit->sourceVersion,
            ];
        }

        foreach ($this->positions->positions($companyStableId) as $position) {
            /** @var PeopleWorkforcePosition $position */
            $records[$this->key(WorkforceResourceType::Position, $position->reference->externalId)] = [
                'reference' => $position->reference->externalId,
                'resource' => WorkforceResourceType::Position->value,
                'active' => $position->active,
                'source_version' => $position->sourceVersion,
            ];
        }

        foreach ($this->directory->employees($companyStableId) as $employee) {
            /** @var PeopleWorkforceEmployee $employee */
            $records[$this->key(WorkforceResourceType::Employee, $employee->reference->externalId)] = [
                'reference' => $employee->reference->externalId,
                'resource' => WorkforceResourceType::Employee->value,
                'active' => $employee->active,
                'source_version' => $employee->sourceVersion,
            ];
        }

        return $records;
    }

    /**
     * @return array<string, array{reference: string, resource: string, active: bool, source_version: ?string}>
     */
    private function projectedRecords(int $connectionId, int $tenantId, string $companyStableId): array
    {
        /** @var Collection<int, ExternalIdentity> $identities */
        $identities = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->whereNull('effective_to')
            ->whereNull('replaced_by_identity_id')
            ->whereIn('resource_type', [
                WorkforceResourceType::Company->value,
                WorkforceResourceType::OrganizationUnit->value,
                WorkforceResourceType::Position->value,
                WorkforceResourceType::Employee->value,
            ])
            ->get()
            ->keyBy(static fn (ExternalIdentity $identity): int => (int) $identity->workforce_entity_id);

        if ($identities->isEmpty()) {
            return [];
        }

        $companyIdentity = $identities->first(
            static fn (ExternalIdentity $identity): bool => $identity->resource_type === WorkforceResourceType::Company->value
                && (string) $identity->external_id === $companyStableId,
        );

        if ($companyIdentity === null) {
            return [];
        }

        $companyEntityId = (int) $companyIdentity->workforce_entity_id;
        $entityIds = $identities->keys()->all();
        $records = [];

        $companyProjection = WorkforceCompanyProjection::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('workforce_entity_id', $companyEntityId)
            ->first();

        if ($companyProjection !== null) {
            $records[$this->key(WorkforceResourceType::Company, (string) $companyIdentity->external_id)] = [
                'reference' => (string) $companyIdentity->external_id,
                'resource' => WorkforceResourceType::Company->value,
                'active' => (bool) $companyProjection->active,
                'source_version' => $companyProjection->source_version !== null
                    ? (string) $companyProjection->source_version
                    : null,
            ];
        }

        foreach ([
            [WorkforceResourceType::OrganizationUnit, WorkforceOrganizationUnitProjection::class],
            [WorkforceResourceType::Position, WorkforcePositionProjection::class],
            [WorkforceResourceType::Employee, WorkforceEmployeeProjection::class],
        ] as [$type, $model]) {
            $rows = $model::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereIn('workforce_entity_id', $entityIds)
                ->whereNull('privacy_deleted_at')
                ->get();

            foreach ($rows as $row) {
                $identity = $identities->get((int) $row->workforce_entity_id);
                if ($identity === null || (string) $identity->resource_type !== $type->value) {
                    continue;
                }

                $records[$this->key($type, (string) $identity->external_id)] = [
                    'reference' => (string) $identity->external_id,
                    'resource' => $type->value,
                    'active' => (bool) $row->active,
                    'source_version' => $row->source_version !== null ? (string) $row->source_version : null,
                ];
            }
        }

        return $records;
    }

    private function key(WorkforceResourceType $type, string $externalId): string
    {
        return $type->value.':'.$externalId;
    }
}
