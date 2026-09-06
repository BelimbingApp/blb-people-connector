<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Database\Services\DataShare\DataSharePrivateStorage;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WorkforceSubjectImportResult;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectImportException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Import only the identity mapping and append-only history from a subject export. */
final class WorkforceSubjectImporter
{
    public const IMPORT_CAPABILITY = 'people-connector.identity.import';

    private const FORMAT = 'belimbing-data-share/people-connector-subject/v1';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly DataSharePrivateStorage $storage,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audits,
    ) {}

    public function import(Actor $actor, int $connectionId, string $packageId): WorkforceSubjectImportResult
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $connection = $this->connections->get($connectionId);
        $this->authorization->authorize($actor, self::IMPORT_CAPABILITY, new ResourceContext(
            type: 'people-connector.identity', id: $packageId, companyId: $connection->company_id, tenantId: $tenantId,
        ));
        if ($actor->tenantId !== $tenantId || ($connection->company_id !== null && $actor->companyId !== $connection->company_id)) {
            throw new ProviderAuthorizationException('connector', 'subject_import', 'The operator must belong to the target tenant and company.');
        }

        $package = $this->readPackage($packageId);
        $tables = $package['tables'];
        $entities = $tables[(new WorkforceEntity)->getTable()] ?? [];
        $identities = $tables[(new ExternalIdentity)->getTable()] ?? [];
        $snapshots = $tables[(new WorkforceSnapshot)->getTable()] ?? [];
        if (! is_array($entities) || count($entities) !== 1 || ! is_array($identities) || $identities === [] || ! is_array($snapshots)) {
            throw new WorkforceSubjectImportException('The subject package does not contain one entity with identity history.');
        }
        $entitySource = $entities[0];
        if (! is_array($entitySource) || ! is_int($sourceEntityId = $entitySource['id'] ?? null) || ($package['subject']['workforce_entity_id'] ?? null) !== $sourceEntityId) {
            throw new WorkforceSubjectImportException('The subject manifest does not identify its one exported entity.');
        }
        $sourceConnections = [];
        foreach ($identities as $identity) {
            if (! is_array($identity)
                || ! is_int($identity['id'] ?? null)
                || ! is_int($identity['connection_id'] ?? null)
                || ($identity['provider_id'] ?? null) !== $connection->provider_id
                || ($identity['workforce_entity_id'] ?? null) !== $sourceEntityId
                || ($identity['resource_type'] ?? null) !== ($entitySource['resource_type'] ?? null)
                || ! is_string($identity['external_id'] ?? null)) {
                throw new WorkforceSubjectImportException('Every exported identity must belong to the subject and match the target provider.');
            }
            $sourceConnections[$identity['id']] = $identity['connection_id'];
        }
        if (count($sourceConnections) !== count($identities)) {
            throw new WorkforceSubjectImportException('The subject package repeats an identity id.');
        }
        foreach ($snapshots as $snapshot) {
            $sourceIdentity = is_array($snapshot) ? ($snapshot['external_identity_id'] ?? null) : null;
            $sourceConnection = is_array($snapshot) ? ($snapshot['connection_id'] ?? null) : null;
            if (! is_array($snapshot)
                || ($snapshot['workforce_entity_id'] ?? null) !== $sourceEntityId
                || ($snapshot['resource_type'] ?? null) !== ($entitySource['resource_type'] ?? null)
                || ($sourceIdentity === null ? ! in_array($sourceConnection, $sourceConnections, true) : ($sourceConnections[$sourceIdentity] ?? null) !== $sourceConnection)) {
                throw new WorkforceSubjectImportException('Every exported history row must belong to a represented subject identity and connection.');
            }
        }

        try {
            return DB::transaction(function () use ($actor, $connection, $entities, $identities, $packageId, $snapshots, $tenantId): WorkforceSubjectImportResult {
                $this->connections->get((int) $connection->id, lock: true);
                foreach ($identities as $identity) {
                    $exists = ExternalIdentity::query()->forTenant($tenantId)
                        ->where('connection_id', $connection->id)
                        ->where('resource_type', $identity['resource_type'])
                        ->where('external_id_hash', hash('sha256', $identity['external_id']))
                        ->exists();
                    if ($exists) {
                        throw new WorkforceSubjectImportException("The target tenant already maps external id [{$identity['external_id']}].");
                    }
                }

                $entitySource = $entities[0];
                if (($entitySource['merged_into_entity_id'] ?? null) !== null) {
                    throw new WorkforceSubjectImportException('A subject merged into an entity outside this package cannot be imported.');
                }
                $entity = WorkforceEntity::query()->create($this->rewritten($entitySource, $tenantId, ['merged_into_entity_id' => null]));
                $identityIds = [];
                foreach ($identities as $source) {
                    $oldId = (int) $source['id'];
                    $identityIds[$oldId] = (int) ExternalIdentity::query()->create($this->rewritten($source, $tenantId, [
                        'connection_id' => (int) $connection->id,
                        'workforce_entity_id' => (int) $entity->id,
                        'replaced_by_identity_id' => null,
                        'external_id_hash' => hash('sha256', $source['external_id']),
                    ]))->id;
                }
                foreach ($identities as $source) {
                    $replacement = $source['replaced_by_identity_id'] ?? null;
                    if ($replacement !== null && ! isset($identityIds[(int) $replacement])) {
                        throw new WorkforceSubjectImportException('An identity replacement points outside this subject package.');
                    }
                    if ($replacement !== null) {
                        DB::table((new ExternalIdentity)->getTable())->where('id', $identityIds[(int) $source['id']])
                            ->update(['replaced_by_identity_id' => $identityIds[(int) $replacement]]);
                    }
                }
                foreach ($snapshots as $source) {
                    $sourceIdentity = $source['external_identity_id'] ?? null;
                    if ($sourceIdentity !== null && ! isset($identityIds[(int) $sourceIdentity])) {
                        throw new WorkforceSubjectImportException('A history row points outside this subject package.');
                    }
                    WorkforceSnapshot::query()->create($this->rewritten($source, $tenantId, [
                        'connection_id' => (int) $connection->id,
                        'workforce_entity_id' => (int) $entity->id,
                        'external_identity_id' => $sourceIdentity === null ? null : $identityIds[(int) $sourceIdentity],
                    ]));
                }
                $this->audits->record($actor, OperatorAuditOperation::SubjectHistoryImported, (int) $connection->id, null, null, [], [
                    'package_id' => $packageId,
                    'workforce_entity_id' => (int) $entity->id,
                    'rows' => count($identities) + count($snapshots) + 1,
                ]);

                return new WorkforceSubjectImportResult($packageId, (int) $entity->id, count($identities), count($snapshots));
            });
        } catch (WorkforceSubjectImportException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new WorkforceSubjectImportException('The subject history import failed; no changes were written.', previous: $failure);
        }
    }

    /** @return array<string, mixed> */
    private function readPackage(string $packageId): array
    {
        if (! Str::isUuid($packageId)) {
            throw new WorkforceSubjectImportException('The subject package id must be a UUID.');
        }
        $path = $this->storage->incomingPath($packageId);
        if (! $this->storage->disk()->exists($path)) {
            throw new WorkforceSubjectImportException("No incoming subject package [{$packageId}].");
        }
        try {
            $package = json_decode($this->storage->disk()->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new WorkforceSubjectImportException('The incoming subject package is not valid JSON.', previous: $failure);
        }
        if (! is_array($package) || ($package['format'] ?? null) !== self::FORMAT || ($package['import_policy'] ?? null) !== 'identity-history') {
            throw new WorkforceSubjectImportException('The incoming package is not an importable connector subject export.');
        }
        if (($package['package_id'] ?? null) !== $packageId || ! is_array($package['tables'] ?? null)) {
            throw new WorkforceSubjectImportException('The incoming subject package identity or table manifest is invalid.');
        }

        return $package;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $overrides @return array<string, mixed> */
    private function rewritten(array $source, int $tenantId, array $overrides): array
    {
        unset($source['id']);

        return [...$source, 'tenant_id' => $tenantId, ...$overrides];
    }
}
