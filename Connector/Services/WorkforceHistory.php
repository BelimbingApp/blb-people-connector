<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WorkforceHistoryEvent;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceHistoryConflictException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;

final class WorkforceHistory
{
    public function __construct(private TenantContext $tenantContext) {}

    public function record(
        ProviderConnection $connection,
        WorkforceEntity $entity,
        ?ExternalIdentity $identity,
        WorkforceHistoryEvent $event,
        \DateTimeInterface $effectiveAt,
        \DateTimeInterface $observedAt,
        ?WorkforceProvenance $provenance = null,
        ?string $sourceVersion = null,
    ): WorkforceSnapshot {
        $tenantId = $this->tenantContext->requireTenantId();
        $payload = $event->payload();

        if ($sourceVersion !== null && strlen($sourceVersion) > 100) {
            throw new WorkforceHistoryConflictException('Workforce history source versions cannot exceed 100 bytes.');
        }

        if ((int) $connection->tenant_id !== $tenantId
            || (int) $entity->tenant_id !== $tenantId
            || ($identity !== null && (
                (int) $identity->tenant_id !== $tenantId
                || (int) $identity->connection_id !== (int) $connection->id
                || (int) $identity->workforce_entity_id !== (int) $entity->id
                || $identity->resource_type !== $entity->resource_type
            ))) {
            throw new WorkforceHistoryConflictException(
                'Workforce history provenance must describe one tenant, connection, identity, and entity.',
            );
        }

        if ($event->resourceType->value !== $entity->resource_type) {
            throw new WorkforceHistoryConflictException(
                'Workforce history events must match the canonical entity resource type.',
            );
        }

        $keyMaterial = [
            'connection' => (int) $connection->id,
            'entity' => (int) $entity->id,
            'identity' => $identity?->id,
            'event' => $event->type->value,
            'effective_at' => $this->timestampKey($effectiveAt),
            'observed_at' => $this->timestampKey($observedAt),
            'source_version' => $sourceVersion,
            'payload' => $this->canonicalize($payload),
            'provenance' => $provenance?->toArray(),
        ];
        $eventKey = hash('sha256', json_encode($keyMaterial, JSON_THROW_ON_ERROR));

        return WorkforceSnapshot::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'event_key' => $eventKey,
            ],
            [
                'connection_id' => $connection->id,
                'workforce_entity_id' => $entity->id,
                'external_identity_id' => $identity?->id,
                'event_type' => $event->type->value,
                'resource_type' => $entity->resource_type,
                'effective_at' => $effectiveAt,
                'observed_at' => $observedAt,
                'source_version' => $sourceVersion,
                'payload' => $payload,
                'provenance' => $provenance?->toArray(),
                'created_at' => now(),
            ],
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function timestampKey(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
