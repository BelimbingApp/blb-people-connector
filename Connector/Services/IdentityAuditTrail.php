<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\IdentityAuditTrailException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;

/** Read-only union of immutable identity history and attributed operator actions. */
final class IdentityAuditTrail
{
    public const READ_CAPABILITY = 'people-connector.identity.audit';

    /** @var list<string> */
    private const IDENTITY_EVENTS = [
        'identity_attached',
        'identity_remapped',
        'identity_handed_over',
        'entity_merged',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    /** @return list<array{event: string, actor: string, occurred_at: string}> */
    public function forExternalId(Actor $actor, string $externalId): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $externalId = trim($externalId);
        if ($externalId === '' || strlen($externalId) > 512) {
            throw new IdentityAuditTrailException('An identity audit trail requires one valid external id.');
        }

        $identities = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('external_id_hash', hash('sha256', $externalId))
            ->where('external_id', $externalId)
            ->orderBy('id')
            ->get();
        if ($identities->isEmpty()) {
            throw new IdentityAuditTrailException("External identity [{$externalId}] was not found in the current tenant.");
        }

        $connectionIds = $identities->pluck('connection_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values();
        $companyIds = ProviderConnection::query()->forTenant($tenantId)
            ->whereIn('id', $connectionIds)
            ->whereNotNull('company_id')
            ->pluck('company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($companyIds->count() > 1) {
            throw new IdentityAuditTrailException("External identity [{$externalId}] spans multiple companies and needs a narrower provider reference.");
        }

        $companyId = $companyIds->isEmpty() ? null : (int) $companyIds->first();
        $this->authorization->authorize($actor, self::READ_CAPABILITY, new ResourceContext(
            type: 'people-connector.identity', id: $externalId, companyId: $companyId, tenantId: $tenantId,
        ));
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId || ($companyId !== null && $actor->companyId !== $companyId)) {
            throw new ProviderAuthorizationException('connector', 'identity_audit', 'The operator must belong to the identity tenant and company.');
        }

        $identityIds = $identities->pluck('id')->map(static fn (mixed $id): int => (int) $id);
        $entityIds = $identities->pluck('workforce_entity_id')->map(static fn (mixed $id): int => (int) $id)->unique();
        $externalIds = $identities->pluck('external_id')->all();
        $events = WorkforceSnapshot::query()->forTenant($tenantId)
            ->whereIn('external_identity_id', $identityIds)
            ->whereIn('event_type', self::IDENTITY_EVENTS)
            ->get()
            ->map(static fn (WorkforceSnapshot $snapshot): array => [
                'event' => (string) $snapshot->event_type,
                'actor' => 'system',
                'occurred_at' => $snapshot->observed_at->utc()->format(DATE_ATOM),
            ])
            ->all();

        $operatorAudits = OperatorAudit::query()->forTenant($tenantId)
            ->where(static fn ($query) => $query
                ->whereIn('connection_id', $connectionIds)
                ->orWhereIn('related_connection_id', $connectionIds))
            ->get();

        foreach ($operatorAudits as $audit) {
            $operation = $audit->operation;
            if (! $operation instanceof OperatorAuditOperation || ! $this->matches($audit, $operation, $externalIds, $entityIds->all())) {
                continue;
            }
            $events[] = [
                'event' => $operation->value,
                'actor' => $audit->actor_type.':'.$audit->actor_id,
                'occurred_at' => $audit->occurred_at->utc()->format(DATE_ATOM),
            ];
        }

        usort($events, static fn (array $left, array $right): int => [$left['occurred_at'], $left['event']] <=> [$right['occurred_at'], $right['event']]);

        return $events;
    }

    /** @param list<string> $externalIds @param list<int> $entityIds */
    private function matches(OperatorAudit $audit, OperatorAuditOperation $operation, array $externalIds, array $entityIds): bool
    {
        $before = (array) $audit->before_summary;
        $after = (array) $audit->after_summary;

        return match ($operation) {
            OperatorAuditOperation::IdentitiesRemapped => array_intersect(
                [...(array) ($before['external_ids'] ?? []), ...(array) ($after['external_ids'] ?? [])],
                $externalIds,
            ) !== [],
            OperatorAuditOperation::SubjectHistoryExported,
            OperatorAuditOperation::SubjectHistoryImported => in_array((int) ($after['workforce_entity_id'] ?? 0), $entityIds, true),
            OperatorAuditOperation::WebhookReplayed => true,
            OperatorAuditOperation::SyncPass => ($after['pass'] ?? null) === 'replay',
            default => false,
        };
    }
}
