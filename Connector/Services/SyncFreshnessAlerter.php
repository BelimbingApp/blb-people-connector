<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceFreshness;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;

/**
 * Turns a freshness breach into something an operator can see and act on.
 *
 * The freshness policy answers "may I rely on this right now" for one caller at
 * one instant. Nobody is watching it. This puts the breach on the reconciliation
 * queue instead, so a connection that quietly stopped syncing is visible rather
 * than only refusing whoever happens to ask next.
 */
final class SyncFreshnessAlerter
{
    public const ISSUE_KIND = 'sync_stale';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkforceFreshnessPolicy $freshness,
        private readonly ReconciliationIssueStore $issues,
    ) {}

    /**
     * Raise, keep, or clear the stale alert for one connection.
     *
     * Returns the open issue while the connection is in breach, and null when
     * there is nothing for an operator to do.
     */
    public function review(int $connectionId, ?\DateTimeImmutable $now = null): ?ReconciliationIssue
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $freshness = $this->freshness->for($connectionId, $now);
        $observedAt = $freshness->checkedAt;

        // An inactive connection is stale by definition and no pass will ever
        // clear it, so alerting on one would hand the operator a queue entry
        // they cannot act on. Deactivation is a decision someone already made.
        if (! $freshness->isStale() || $freshness->staleReason === WorkforceFreshness::REASON_CONNECTION_INACTIVE) {
            $this->clear($tenantId, $connectionId, $observedAt);

            return null;
        }

        return $this->issues->report(
            $connectionId,
            $this->breachWindowKey($connectionId, $freshness),
            self::ISSUE_KIND,
            new ReconciliationIssueDetails(reasonCode: $freshness->staleReason),
            severity: 'warning',
            seenAt: $observedAt,
        );
    }

    /**
     * One key per breach window, so a connection that stays stale keeps one
     * issue however often it is reviewed.
     *
     * The provider watermark is what makes the window: it only moves when a
     * pass actually completes, which is the same event that clears the alert.
     * A later breach therefore carries a different key and cannot collide with
     * the resolved issue from the previous window.
     */
    private function breachWindowKey(int $connectionId, WorkforceFreshness $freshness): string
    {
        $window = $freshness->asOf?->format(DATE_ATOM) ?? 'never';

        return "sync:stale:{$connectionId}:{$window}";
    }

    private function clear(int $tenantId, int $connectionId, \DateTimeInterface $resolvedAt): void
    {
        $open = ReconciliationIssue::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->where('kind', self::ISSUE_KIND)
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->get();

        foreach ($open as $issue) {
            $this->issues->resolve((int) $issue->id, $resolvedAt);
        }
    }
}
