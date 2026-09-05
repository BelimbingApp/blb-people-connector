<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CutoverRehearsalReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;

/**
 * Rehearse a provider cutover: report what would break, change nothing.
 *
 * The word rehearsal is the promise, so this only reads. Everything it finds is
 * something an operator can fix before the real switch, which is the entire
 * value — a cutover discovered to be wrong afterwards has already lost the
 * workforce's source.
 */
final class CutoverRehearsalService
{
    public const REHEARSE_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly WorkforceFreshnessPolicy $freshness,
    ) {}

    public function rehearse(Actor $actor, int $fromConnectionId, int $toConnectionId): CutoverRehearsalReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::REHEARSE_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'rehearse_cutover',
                message: 'Rehearsing a cutover reads one tenant and requires an actor inside it.',
            );
        }

        $this->connections->get($fromConnectionId);
        $this->connections->get($toConnectionId);

        $freshness = $this->freshness->for($toConnectionId);

        return new CutoverRehearsalReport(
            fromConnectionId: $fromConnectionId,
            toConnectionId: $toConnectionId,
            unmappedIdentities: $this->unmappedIdentities($tenantId, $fromConnectionId, $toConnectionId),
            targetStale: $freshness->isStale(),
            targetStaleReason: $freshness->staleReason,
            openIssues: $this->openIssues($tenantId, $fromConnectionId, $toConnectionId),
        );
    }

    /**
     * Identities still live on the source whose workforce entity the target
     * cannot answer for.
     *
     * Counted by entity rather than by external id, because the two providers
     * name people differently — that is what a replacement is. What matters is
     * whether the person survives the switch, not whether the string does.
     */
    private function unmappedIdentities(int $tenantId, int $fromConnectionId, int $toConnectionId): int
    {
        $targetEntityIds = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $toConnectionId)
            ->pluck('workforce_entity_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $fromConnectionId)
            ->where('state', ExternalIdentity::STATE_ACTIVE)
            ->when($targetEntityIds !== [], fn ($query) => $query->whereNotIn('workforce_entity_id', $targetEntityIds))
            ->count();
    }

    /**
     * Open issues on either connection.
     *
     * The source's count matters as much as the target's: an unanswered
     * question about the old provider does not become answerable by switching
     * providers, it becomes unanswerable.
     */
    private function openIssues(int $tenantId, int $fromConnectionId, int $toConnectionId): int
    {
        return ReconciliationIssue::query()
            ->forTenant($tenantId)
            ->whereIn('connection_id', [$fromConnectionId, $toConnectionId])
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->count();
    }
}
