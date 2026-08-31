<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ExternalIdentityCollisionException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use Illuminate\Support\Facades\DB;

final class WorkforceIdentityStore
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
        private WorkforceHistory $history,
    ) {}

    public function resolveOrCreateIdentity(
        int $connectionId,
        ExternalReference $reference,
        \DateTimeInterface $observedAt,
        ?int $preferredEntityId = null,
        ?WorkforceProvenance $provenance = null,
        ?string $sourceVersion = null,
    ): ExternalIdentity {
        if ($preferredEntityId !== null && $provenance?->reviewReference === null) {
            throw new ExternalIdentityCollisionException(
                'Binding a new provider identity to an existing workforce entity requires reviewed provenance.',
            );
        }

        if ($sourceVersion !== null && strlen($sourceVersion) > 100) {
            throw new ExternalIdentityCollisionException('External identity source versions cannot exceed 100 bytes.');
        }

        return DB::transaction(function () use ($connectionId, $reference, $observedAt, $preferredEntityId, $provenance, $sourceVersion): ExternalIdentity {
            $tenantId = $this->tenantContext->requireTenantId();
            $connection = $this->connections->get($connectionId, lock: true);
            $this->assertReferenceFitsConnection($connection, $reference);

            $identity = $this->findIdentity($connection, $reference, lock: true);

            if ($identity !== null) {
                if ($preferredEntityId !== null) {
                    $preferred = $this->entity($preferredEntityId, $reference->resourceType->value, lock: true);

                    if ($this->canonicalEntity($preferred, lock: true)->id !== $this->canonicalEntityForIdentity($identity, lock: true)->id) {
                        throw new ExternalIdentityCollisionException(
                            'The external reference is already bound to another canonical workforce entity.',
                        );
                    }
                }

                if ($identity->last_observed_at->lessThan($observedAt)) {
                    $identity->last_observed_at = $observedAt;
                    $identity->source_version = $sourceVersion ?? $identity->source_version;
                    $identity->save();
                }

                return $identity;
            }

            $entity = $preferredEntityId === null
                ? WorkforceEntity::query()->create([
                    'tenant_id' => $tenantId,
                    'resource_type' => $reference->resourceType->value,
                    'state' => WorkforceEntity::STATE_ACTIVE,
                    'first_seen_at' => $observedAt,
                ])
                : $this->canonicalEntity(
                    $this->entity($preferredEntityId, $reference->resourceType->value, lock: true),
                    lock: true,
                );

            $identity = ExternalIdentity::query()->create([
                'tenant_id' => $tenantId,
                'connection_id' => $connection->id,
                'workforce_entity_id' => $entity->id,
                'provider_id' => $reference->providerId,
                'resource_type' => $reference->resourceType->value,
                'external_id' => $reference->externalId,
                'external_id_hash' => $this->externalIdHash($reference),
                'state' => ExternalIdentity::STATE_ACTIVE,
                'source_version' => $sourceVersion,
                'effective_from' => $observedAt,
                'last_observed_at' => $observedAt,
                'provenance' => $provenance?->toArray(),
            ]);

            $this->history->record(
                $connection,
                $entity,
                $identity,
                'identity_attached',
                $observedAt,
                $observedAt,
                ['external_id' => $reference->externalId],
                $provenance,
                $sourceVersion,
            );

            return $identity;
        });
    }

    public function resolve(int $connectionId, ExternalReference $reference): WorkforceEntity
    {
        $connection = $this->connections->get($connectionId);
        $this->assertReferenceFitsConnection($connection, $reference);
        $identity = $this->findIdentity($connection, $reference)
            ?? throw new ConnectorRecordNotFoundException('The external workforce identity was not found in the current tenant.');

        return $this->canonicalEntityForIdentity($identity);
    }

    public function remap(
        int $connectionId,
        ExternalReference $oldReference,
        ExternalReference $newReference,
        \DateTimeInterface $occurredAt,
        ?WorkforceProvenance $provenance = null,
    ): ExternalIdentity {
        if ($provenance?->reviewReference === null) {
            throw new ExternalIdentityCollisionException('Identity remapping requires a review reference.');
        }

        if ($oldReference->providerId !== $newReference->providerId
            || $oldReference->resourceType !== $newReference->resourceType) {
            throw new ExternalIdentityCollisionException('An identity remap cannot cross providers or workforce resource types.');
        }

        if ($oldReference->externalId === $newReference->externalId) {
            throw new ExternalIdentityCollisionException('An identity remap requires a different external identifier.');
        }

        return DB::transaction(function () use ($connectionId, $oldReference, $newReference, $occurredAt, $provenance): ExternalIdentity {
            $connection = $this->connections->get($connectionId, lock: true);
            $this->assertReferenceFitsConnection($connection, $oldReference);
            $oldIdentity = $this->findIdentity($connection, $oldReference, lock: true)
                ?? throw new ConnectorRecordNotFoundException('The superseded external identity was not found in the current tenant.');

            if ($oldIdentity->state === ExternalIdentity::STATE_REMAPPED) {
                $replacement = ExternalIdentity::query()
                    ->forTenant($this->tenantContext->requireTenantId())
                    ->whereKey($oldIdentity->replaced_by_identity_id)
                    ->first();

                if ($replacement !== null && $replacement->external_id === $newReference->externalId) {
                    return $replacement;
                }

                throw new ExternalIdentityCollisionException('The superseded identity has already been remapped elsewhere.');
            }

            if ($oldIdentity->state !== ExternalIdentity::STATE_ACTIVE) {
                throw new ExternalIdentityCollisionException('Only an active external identity can be remapped.');
            }

            $this->assertTransitionChronology($occurredAt, $oldIdentity->last_observed_at);

            $entity = $this->canonicalEntityForIdentity($oldIdentity, lock: true);
            $newIdentity = $this->findIdentity($connection, $newReference, lock: true);

            if ($newIdentity === null) {
                $newIdentity = $this->resolveOrCreateIdentity(
                    $connectionId,
                    $newReference,
                    $occurredAt,
                    preferredEntityId: (int) $entity->id,
                    provenance: $provenance,
                );
            } elseif ($this->canonicalEntityForIdentity($newIdentity, lock: true)->id !== $entity->id) {
                throw new ExternalIdentityCollisionException(
                    'The replacement external reference is already bound to another canonical workforce entity.',
                );
            }

            $this->assertTransitionChronology($occurredAt, $newIdentity->last_observed_at);

            $oldIdentity->fill([
                'state' => ExternalIdentity::STATE_REMAPPED,
                'replaced_by_identity_id' => $newIdentity->id,
                'effective_to' => $occurredAt,
                'last_observed_at' => $occurredAt,
            ])->save();

            $this->history->record(
                $connection,
                $entity,
                $oldIdentity,
                'identity_remapped',
                $occurredAt,
                $occurredAt,
                [
                    'superseded_external_id' => $oldReference->externalId,
                    'replacement_external_id' => $newReference->externalId,
                    'replacement_identity_id' => $newIdentity->id,
                ],
                $provenance,
            );

            return $newIdentity;
        });
    }

    public function merge(
        int $connectionId,
        ExternalReference $supersededReference,
        ExternalReference $survivingReference,
        \DateTimeInterface $occurredAt,
        ?WorkforceProvenance $provenance = null,
    ): WorkforceEntity {
        if ($provenance?->reviewReference === null) {
            throw new ExternalIdentityCollisionException('Workforce entity merges require a review reference.');
        }

        if ($supersededReference->providerId !== $survivingReference->providerId
            || $supersededReference->resourceType !== $survivingReference->resourceType) {
            throw new ExternalIdentityCollisionException('A workforce merge cannot cross providers or resource types.');
        }

        return DB::transaction(function () use ($connectionId, $supersededReference, $survivingReference, $occurredAt, $provenance): WorkforceEntity {
            $connection = $this->connections->get($connectionId, lock: true);
            $supersededIdentity = $this->requiredIdentity($connection, $supersededReference, lock: true);
            $survivingIdentity = $this->requiredIdentity($connection, $survivingReference, lock: true);
            $superseded = $this->canonicalEntityForIdentity($supersededIdentity, lock: true);
            $survivor = $this->canonicalEntityForIdentity($survivingIdentity, lock: true);

            if ($superseded->id === $survivor->id) {
                return $survivor;
            }

            if ($superseded->resource_type !== $survivor->resource_type) {
                throw new ExternalIdentityCollisionException('A workforce merge cannot cross resource types.');
            }

            $this->assertTransitionChronology(
                $occurredAt,
                $supersededIdentity->last_observed_at,
                $survivingIdentity->last_observed_at,
            );

            $superseded->fill([
                'state' => WorkforceEntity::STATE_MERGED,
                'merged_into_entity_id' => $survivor->id,
                'merged_at' => $occurredAt,
            ])->save();

            ExternalIdentity::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->where('workforce_entity_id', $superseded->id)
                ->where('state', ExternalIdentity::STATE_ACTIVE)
                ->get()
                ->each(function (ExternalIdentity $identity) use ($occurredAt): void {
                    $identity->fill([
                        'state' => ExternalIdentity::STATE_MERGED,
                        'effective_to' => $occurredAt,
                        'last_observed_at' => $occurredAt,
                    ])->save();
                });

            $this->retireCurrentProjection((int) $superseded->id, $occurredAt);

            $this->history->record(
                $connection,
                $superseded,
                $supersededIdentity,
                'entity_merged',
                $occurredAt,
                $occurredAt,
                [
                    'superseded_entity_id' => $superseded->id,
                    'surviving_entity_id' => $survivor->id,
                    'surviving_external_id' => $survivingReference->externalId,
                ],
                $provenance,
            );

            return $survivor;
        });
    }

    public function deactivate(
        int $connectionId,
        ExternalReference $reference,
        \DateTimeInterface $occurredAt,
        ?WorkforceProvenance $provenance = null,
    ): WorkforceEntity {
        if ($provenance === null) {
            throw new ExternalIdentityCollisionException('External identity deactivation requires source provenance.');
        }

        return DB::transaction(function () use ($connectionId, $reference, $occurredAt, $provenance): WorkforceEntity {
            $connection = $this->connections->get($connectionId, lock: true);
            $identity = $this->requiredIdentity($connection, $reference, lock: true);
            $rawEntity = $this->entity((int) $identity->workforce_entity_id, $reference->resourceType->value, lock: true);
            $canonical = $this->canonicalEntity($rawEntity, lock: true);

            if ($identity->state === ExternalIdentity::STATE_INACTIVE) {
                return $canonical;
            }

            if ($identity->state !== ExternalIdentity::STATE_ACTIVE) {
                throw new ExternalIdentityCollisionException('Only an active external identity can be deactivated.');
            }

            $this->assertTransitionChronology($occurredAt, $identity->last_observed_at);

            $identity->fill([
                'state' => ExternalIdentity::STATE_INACTIVE,
                'effective_to' => $occurredAt,
                'last_observed_at' => $occurredAt,
            ])->save();

            $otherActiveIdentityExists = ExternalIdentity::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->where('workforce_entity_id', $rawEntity->id)
                ->whereKeyNot($identity->id)
                ->where('state', ExternalIdentity::STATE_ACTIVE)
                ->exists();

            if (! $otherActiveIdentityExists && $rawEntity->state !== WorkforceEntity::STATE_MERGED) {
                $rawEntity->fill([
                    'state' => WorkforceEntity::STATE_INACTIVE,
                    'deactivated_at' => $occurredAt,
                ])->save();
                $this->retireCurrentProjection((int) $rawEntity->id, $occurredAt);
            }

            $this->history->record(
                $connection,
                $rawEntity,
                $identity,
                'identity_deactivated',
                $occurredAt,
                $occurredAt,
                ['external_id' => $reference->externalId],
                $provenance,
            );

            return $canonical;
        });
    }

    public function assertActive(ExternalIdentity $identity): void
    {
        if ((int) $identity->tenant_id !== $this->tenantContext->requireTenantId()
            || $identity->state !== ExternalIdentity::STATE_ACTIVE) {
            throw new ExternalIdentityCollisionException('Only an active current identity may update a workforce projection.');
        }
    }

    private function requiredIdentity(
        ProviderConnection $connection,
        ExternalReference $reference,
        bool $lock = false,
    ): ExternalIdentity {
        $this->assertReferenceFitsConnection($connection, $reference);

        return $this->findIdentity($connection, $reference, $lock)
            ?? throw new ConnectorRecordNotFoundException('The external workforce identity was not found in the current tenant.');
    }

    private function findIdentity(
        ProviderConnection $connection,
        ExternalReference $reference,
        bool $lock = false,
    ): ?ExternalIdentity {
        $query = ExternalIdentity::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->where('connection_id', $connection->id)
            ->where('resource_type', $reference->resourceType->value)
            ->where('external_id_hash', $this->externalIdHash($reference));

        if ($lock) {
            $query->lockForUpdate();
        }

        $identity = $query->first();

        if ($identity !== null && $identity->external_id !== $reference->externalId) {
            throw new ExternalIdentityCollisionException('An external identity hash collision was detected.');
        }

        return $identity;
    }

    private function entity(int $entityId, string $resourceType, bool $lock = false): WorkforceEntity
    {
        $query = WorkforceEntity::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->whereKey($entityId)
            ->where('resource_type', $resourceType);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw new ConnectorRecordNotFoundException('The canonical workforce entity was not found in the current tenant.');
    }

    private function canonicalEntityForIdentity(ExternalIdentity $identity, bool $lock = false): WorkforceEntity
    {
        return $this->canonicalEntity(
            $this->entity((int) $identity->workforce_entity_id, $identity->resource_type, $lock),
            $lock,
        );
    }

    private function canonicalEntity(WorkforceEntity $entity, bool $lock = false): WorkforceEntity
    {
        $visited = [];

        while ($entity->merged_into_entity_id !== null) {
            if (isset($visited[$entity->id])) {
                throw new ExternalIdentityCollisionException('A cycle exists in the canonical workforce merge chain.');
            }

            $visited[$entity->id] = true;
            $entity = $this->entity((int) $entity->merged_into_entity_id, $entity->resource_type, $lock);
        }

        return $entity;
    }

    private function assertReferenceFitsConnection(
        ProviderConnection $connection,
        ExternalReference $reference,
    ): void {
        if ($connection->provider_id !== $reference->providerId) {
            throw new ExternalIdentityCollisionException('The external reference does not belong to this provider connection.');
        }

        if (strlen($reference->externalId) > 512) {
            throw new ExternalIdentityCollisionException('External workforce identifiers cannot exceed 512 bytes.');
        }
    }

    private function externalIdHash(ExternalReference $reference): string
    {
        return hash('sha256', $reference->externalId);
    }

    private function assertTransitionChronology(
        \DateTimeInterface $occurredAt,
        \DateTimeInterface ...$previousObservations,
    ): void {
        foreach ($previousObservations as $previousObservation) {
            if ($occurredAt->getTimestamp() < $previousObservation->getTimestamp()) {
                throw new ExternalIdentityCollisionException(
                    'Workforce identity transitions cannot predate the latest observed provider fact.',
                );
            }
        }
    }

    private function retireCurrentProjection(int $entityId, \DateTimeInterface $occurredAt): void
    {
        foreach ([
            WorkforceCompanyProjection::class,
            WorkforceOrganizationUnitProjection::class,
            WorkforcePositionProjection::class,
            WorkforceEmployeeProjection::class,
        ] as $projectionModel) {
            $projection = $projectionModel::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->where('workforce_entity_id', $entityId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                continue;
            }

            $projection->fill([
                'active' => false,
                'effective_at' => $occurredAt,
                'observed_at' => $occurredAt,
            ])->save();
        }
    }
}
