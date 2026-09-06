<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Database\Services\DataShare\CanonicalJson;
use App\Base\Database\Services\DataShare\DataSharePrivateStorage;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WorkforceSubjectExportResult;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectExportException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Build a protected DataShare package for one data subject. */
final class WorkforceSubjectExporter
{
    public const EXPORT_CAPABILITY = 'people-connector.identity.export';

    /** @var array<class-string, list<string>> */
    private const JSON_COLUMNS = [
        ExternalIdentity::class => ['provenance'],
        WorkforceSnapshot::class => ['payload', 'provenance'],
        ReconciliationIssue::class => ['details'],
        OperatorAudit::class => ['before_summary', 'after_summary'],
    ];

    /** @var list<class-string> */
    private const SUBJECT_MODELS = [
        WorkforceEntity::class,
        ExternalIdentity::class,
        WorkforceCompanyProjection::class,
        WorkforceOrganizationUnitProjection::class,
        WorkforcePositionProjection::class,
        WorkforceEmployeeProjection::class,
        WorkforceSnapshot::class,
        ReconciliationIssue::class,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly DataSharePrivateStorage $storage,
        private readonly OperatorAuditLog $audits,
    ) {}

    public function export(Actor $actor, int $workforceEntityId): WorkforceSubjectExportResult
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $entity = WorkforceEntity::query()->forTenant($tenantId)->whereKey($workforceEntityId)->first();

        if ($entity === null) {
            throw new WorkforceSubjectExportException("Workforce subject [{$workforceEntityId}] was not found in the current tenant.");
        }

        $identities = $this->rows(ExternalIdentity::class, $tenantId, $workforceEntityId);
        $connectionIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['connection_id'], $identities)));
        $companyIds = $connectionIds === [] ? [] : DB::table((new ProviderConnection)->getTable())
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $connectionIds)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($companyIds) > 1) {
            throw new WorkforceSubjectExportException('A subject linked to more than one platform company requires an explicit company decision before export.');
        }

        $companyId = $companyIds[0] ?? null;
        $this->authorization->authorize($actor, self::EXPORT_CAPABILITY, new ResourceContext(
            type: 'people-connector.identity',
            id: $workforceEntityId,
            companyId: $companyId,
            tenantId: $tenantId,
        ));
        $this->assertActor($actor, $tenantId, $companyId);

        $tables = [];
        foreach (self::SUBJECT_MODELS as $model) {
            $tables[(new $model)->getTable()] = $model === WorkforceEntity::class
                ? $this->entityRows($tenantId, $workforceEntityId)
                : ($model === ExternalIdentity::class ? $identities : $this->rows($model, $tenantId, $workforceEntityId));
        }

        $tables[(new OperatorAudit)->getTable()] = $this->auditRows(
            $tenantId,
            $connectionIds,
            array_column($identities, 'external_id'),
            $workforceEntityId,
        );
        $tables = array_map(fn (array $rows): array => array_map(fn (array $row): array => $this->redact($row), $rows), $tables);
        $counts = array_map('count', $tables);
        $packageId = (string) Str::uuid();
        $package = [
            'format' => 'belimbing-data-share/people-connector-subject/v1',
            'package_id' => $packageId,
            'purpose' => 'data-subject-access-request',
            'import_policy' => 'identity-history',
            'created_at' => now()->utc()->format(DATE_ATOM),
            'tenant_id' => $tenantId,
            'subject' => [
                'workforce_entity_id' => $workforceEntityId,
                'resource_type' => $entity->resource_type,
            ],
            'redactions' => ['credential_references', 'payload_hashes'],
            'counts' => $counts,
            'tables' => $tables,
        ];
        $contents = CanonicalJson::encode($package)."\n";
        $path = $this->storage->outgoingPath($packageId);

        if (! $this->storage->disk()->put($path, $contents)) {
            throw new WorkforceSubjectExportException('The protected DataShare package could not be written.');
        }

        try {
            $this->audits->record(
                $actor,
                OperatorAuditOperation::SubjectHistoryExported,
                $connectionIds[0] ?? null,
                null,
                null,
                [],
                ['workforce_entity_id' => $workforceEntityId, 'package_id' => $packageId, 'rows' => array_sum($counts)],
            );
        } catch (\Throwable $failure) {
            $this->storage->disk()->delete($path);

            throw new WorkforceSubjectExportException('The protected package was removed because its audit record could not be written.', previous: $failure);
        }

        return new WorkforceSubjectExportResult($packageId, $path, hash('sha256', $contents), strlen($contents), $counts);
    }

    /** @return list<array<string, mixed>> */
    private function entityRows(int $tenantId, int $workforceEntityId): array
    {
        return $this->queryRows(WorkforceEntity::class, $tenantId, fn ($query) => $query->where('id', $workforceEntityId));
    }

    /** @param class-string $model @return list<array<string, mixed>> */
    private function rows(string $model, int $tenantId, int $workforceEntityId): array
    {
        return $this->queryRows($model, $tenantId, fn ($query) => $query->where('workforce_entity_id', $workforceEntityId));
    }

    /** @param class-string $model @return list<array<string, mixed>> */
    private function queryRows(string $model, int $tenantId, callable $scope): array
    {
        $query = DB::table((new $model)->getTable())->where('tenant_id', $tenantId)->orderBy('id');

        return $scope($query)->get()->map(function (object $record) use ($model): array {
            $row = (array) $record;
            foreach (self::JSON_COLUMNS[$model] ?? [] as $column) {
                if (is_string($row[$column] ?? null)) {
                    $row[$column] = json_decode($row[$column], true, flags: JSON_THROW_ON_ERROR);
                }
            }

            return $row;
        })->all();
    }

    /** @param list<int> $connectionIds @param list<string> $externalIds @return list<array<string, mixed>> */
    private function auditRows(int $tenantId, array $connectionIds, array $externalIds, int $workforceEntityId): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $included = [];
        foreach ($this->queryRows(OperatorAudit::class, $tenantId, fn ($query) => $query
            ->where(fn ($connections) => $connections->whereIn('connection_id', $connectionIds)->orWhereIn('related_connection_id', $connectionIds))) as $row) {
            $before = $row['before_summary'] ?? [];
            $after = $row['after_summary'] ?? [];
            if (($row['operation'] ?? null) === OperatorAuditOperation::SubjectHistoryExported->value) {
                if ((int) ($after['workforce_entity_id'] ?? 0) === $workforceEntityId) {
                    $included[] = $row;
                }

                continue;
            }

            $matches = array_values(array_intersect(
                [...$this->auditExternalIds($before), ...$this->auditExternalIds($after)],
                $externalIds,
            ));
            if ($matches === []) {
                continue;
            }

            foreach (['before_summary', 'after_summary'] as $summary) {
                foreach ($row[$summary] as $key => $value) {
                    if (str_ends_with($key, 'external_ids') && is_array($value)) {
                        $row[$summary][$key] = array_values(array_intersect($value, $externalIds));
                    }
                }
            }

            $included[] = $row;
        }

        return $included;
    }

    /** @param array<string, mixed> $summary @return list<string> */
    private function auditExternalIds(array $summary): array
    {
        $externalIds = [];
        foreach ($summary as $key => $value) {
            if (str_ends_with($key, 'external_ids') && is_array($value)) {
                $externalIds = [...$externalIds, ...$value];
            }
        }

        return $externalIds;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:credential.*reference|secret_reference|payload_hash)$/i', $key) === 1) {
                $value[$key] = null;
            } elseif (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function assertActor(Actor $actor, int $tenantId, ?int $companyId): void
    {
        if ($actor->tenantId !== $tenantId || ($companyId !== null && $actor->companyId !== $companyId)) {
            throw new ProviderAuthorizationException('connector', 'subject_export', 'The operator must belong to the subject tenant and company.');
        }
    }
}
