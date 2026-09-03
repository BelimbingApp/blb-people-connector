<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceMerge;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Data\WorkforceSyncReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ExternalIdentityCollisionException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceHistoryConflictException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceProjectionConflictException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Drives an adapter's bootstrap and incremental pages through the persistence
 * foundation ([1006]).
 *
 * Every record is applied on its own; a record the stores refuse — same-time
 * contradiction, identifier collision, a company move the contract forbids,
 * a deactivation for a reference nobody has seen — becomes a reconciliation
 * issue and the pass continues, so one bad row cannot stall a provider
 * forever, and nothing is applied "approximately". A merge arriving in the
 * feed is queued the same way rather than applied: merges need a review
 * reference, and an adapter is not a reviewer.
 *
 * The durable checkpoint moves once, after the final page, and only then.
 * A pass that dies on page two leaves page one's rows behind, which is
 * harmless because every write is idempotent on observed time, and leaves
 * no checkpoint, so the next pass starts again rather than from a page it
 * never finished. One stream serves both passes because the bootstrap's
 * resume cursor is what the first incremental read must present.
 */
final class WorkforceSyncRunner
{
    public const ISSUE_KIND_CONFLICT = 'sync_conflict';

    public const ISSUE_KIND_MERGE_REQUESTED = 'sync_merge_requested';

    public const ISSUE_KIND_EMPTY_BOOTSTRAP = 'sync_empty_bootstrap';

    public const ISSUE_KEY_EMPTY_BOOTSTRAP = 'sync:bootstrap:empty';

    public function __construct(
        private TenantContext $tenantContext,
        private ProviderPortResolver $ports,
        private TenantConnectionLocator $connections,
        private WorkforceProjectionStore $projections,
        private WorkforceIdentityStore $identities,
        private SyncCheckpointStore $checkpoints,
        private ReconciliationIssueStore $issues,
    ) {}

    public function bootstrap(Actor $actor, ProviderAdapter $provider, int $connectionId, ?int $limit = null): WorkforceSyncReport
    {
        $connection = $this->connectionFor($provider, $connectionId);
        $stream = WorkforceFreshnessPolicy::stream();
        $provenance = $this->provenance('sync.bootstrap', $provider, $stream);
        $port = $this->ports->read($actor, $provider, PeopleCapability::EmployeeDirectory, BootstrapsWorkforce::class, $this->scopeOf($connection));
        $tally = $this->emptyTally();
        $pageCursor = null;

        do {
            $page = $port->bootstrap(new WorkforcePageRequest($pageCursor, $limit ?? self::pageLimit()));
            $tally['pages']++;

            foreach ([$page->companies, $page->organizationUnits, $page->positions, $page->employees] as $records) {
                foreach ($records as $record) {
                    $this->applyRecord($connectionId, $record, $provenance, $tally);
                }
            }

            $pageCursor = $this->nextCursor($page, $pageCursor);
        } while (! $page->complete);

        if ($this->seen($tally) === 0) {
            // A provider that says "done" with nothing in it is either empty or
            // broken. Either way a human should see it, because every feature
            // reading these projections would otherwise see a clean, fresh, and
            // entirely vacant workforce.
            $this->issues->report(
                $connectionId,
                self::ISSUE_KEY_EMPTY_BOOTSTRAP,
                self::ISSUE_KIND_EMPTY_BOOTSTRAP,
                new ReconciliationIssueDetails(reasonCode: 'no_records', expectedCount: null, observedCount: 0),
                severity: 'warning',
                seenAt: $page->asOf,
            );
        }

        $checkpoint = $this->checkpoints->advanceCompletedPage(
            $connectionId,
            $stream,
            new WorkforceChangePage([], $page->asOf, resumeCursor: $page->resumeCursor, complete: true),
            (int) ($this->checkpoints->current($connectionId, $stream)?->version ?? 0),
        );

        return $this->report($connectionId, $stream, 'bootstrap', $tally, (int) $checkpoint->version, $page->asOf);
    }

    public function incremental(Actor $actor, ProviderAdapter $provider, int $connectionId, ?int $limit = null): WorkforceSyncReport
    {
        $connection = $this->connectionFor($provider, $connectionId);
        $stream = WorkforceFreshnessPolicy::stream();
        $checkpoint = $this->checkpoints->current($connectionId, $stream)
            ?? throw new WorkforceSyncException('Incremental synchronisation needs a completed bootstrap checkpoint to resume from; run the bootstrap pass first.');
        $provenance = $this->provenance('sync.incremental', $provider, $stream);
        $port = $this->ports->read($actor, $provider, PeopleCapability::EmployeeDirectory, ReadsWorkforceChanges::class, $this->scopeOf($connection));
        $tally = $this->emptyTally();
        $pageCursor = null;

        do {
            $page = $port->changes(new WorkforceChangeRequest($checkpoint->resume_cursor, $pageCursor, $limit ?? self::pageLimit()));
            $tally['pages']++;

            foreach ($page->changes as $change) {
                match (true) {
                    $change instanceof WorkforceUpsert => $this->applyRecord($connectionId, $change->record, $provenance, $tally),
                    $change instanceof WorkforceDeactivation => $this->applyDeactivation($connectionId, $change, $provenance, $tally),
                    $change instanceof WorkforceMerge => $this->queueMerge($connectionId, $change, $tally),
                };
            }

            $pageCursor = $this->nextCursor($page, $pageCursor);
        } while (! $page->complete);

        $advanced = $this->checkpoints->advanceCompletedPage($connectionId, $stream, $page, (int) $checkpoint->version);

        return $this->report($connectionId, $stream, 'incremental', $tally, (int) $advanced->version, $page->asOf);
    }

    /** @param  array<string, int>  $tally */
    private function applyRecord(
        int $connectionId,
        WorkforceCompany|WorkforceOrganizationUnit|WorkforcePosition|WorkforceEmployee $record,
        WorkforceProvenance $provenance,
        array &$tally,
    ): void {
        try {
            if ($record->active && $this->isDeactivated($connectionId, $record->reference)) {
                // A re-hire arrives as an ordinary active upsert for a reference
                // that was switched off. The store refuses to project onto a
                // deactivated identity by design; bringing it back is a
                // separate, recorded event, so it is done here as one.
                $this->identities->reactivate($connectionId, $record->reference, $record->observedAt, $provenance);
                $tally['reactivations']++;
            }

            $this->projections->upsert($connectionId, $record, $provenance);
            $tally[match (true) {
                $record instanceof WorkforceCompany => 'companies',
                $record instanceof WorkforceOrganizationUnit => 'organizationUnits',
                $record instanceof WorkforcePosition => 'positions',
                default => 'employees',
            }]++;
        } catch (WorkforceProjectionConflictException|ExternalIdentityCollisionException|CompanyMoveRefusedException|ConnectorRecordNotFoundException|WorkforceHistoryConflictException $refusal) {
            $this->refuse($connectionId, $record->reference, $record->observedAt, $refusal, $tally);
        }
    }

    /** @param  array<string, int>  $tally */
    private function applyDeactivation(int $connectionId, WorkforceDeactivation $change, WorkforceProvenance $provenance, array &$tally): void
    {
        try {
            $this->identities->deactivate($connectionId, $change->reference, $change->occurredAt, $provenance);
            $tally['deactivations']++;
        } catch (ExternalIdentityCollisionException|ConnectorRecordNotFoundException|WorkforceHistoryConflictException $refusal) {
            $this->refuse($connectionId, $change->reference, $change->occurredAt, $refusal, $tally);
        }
    }

    /** @param  array<string, int>  $tally */
    private function queueMerge(int $connectionId, WorkforceMerge $change, array &$tally): void
    {
        $this->issues->report(
            $connectionId,
            'sync:merge:'.$change->supersededReference->resourceType->value.':'.$change->supersededReference->externalId,
            self::ISSUE_KIND_MERGE_REQUESTED,
            new ReconciliationIssueDetails(reasonCode: 'review_required'),
            $change->supersededReference->resourceType->value,
            $change->supersededReference->externalId,
            severity: 'warning',
            seenAt: $change->occurredAt,
        );
        $tally['mergesQueued']++;
    }

    /** @param  array<string, int>  $tally */
    private function refuse(int $connectionId, ExternalReference $reference, \DateTimeInterface $seenAt, \Throwable $refusal, array &$tally): void
    {
        $this->issues->report(
            $connectionId,
            'sync:'.$reference->resourceType->value.':'.$reference->externalId,
            self::ISSUE_KIND_CONFLICT,
            new ReconciliationIssueDetails(reasonCode: self::reasonCode($refusal)),
            $reference->resourceType->value,
            $reference->externalId,
            severity: 'error',
            seenAt: $seenAt,
        );
        $tally['conflicts']++;
    }

    private function isDeactivated(int $connectionId, ExternalReference $reference): bool
    {
        $identity = ExternalIdentity::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->where('connection_id', $connectionId)
            ->where('provider_id', $reference->providerId)
            ->where('resource_type', $reference->resourceType->value)
            ->where('external_id', $reference->externalId)
            ->first();

        return $identity !== null && $identity->state === ExternalIdentity::STATE_INACTIVE;
    }

    private function connectionFor(ProviderAdapter $provider, int $connectionId): ProviderConnection
    {
        $connection = $this->connections->get($connectionId);

        if ($connection->provider_id !== $provider->descriptor()->id) {
            throw new WorkforceSyncException(
                "Provider connection {$connectionId} belongs to provider '{$connection->provider_id}', not '{$provider->descriptor()->id}'.",
            );
        }

        if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new WorkforceSyncException("Provider connection {$connectionId} is not active.");
        }

        return $connection;
    }

    private function scopeOf(ProviderConnection $connection): ProviderScope
    {
        return $connection->company_id === null
            ? ProviderScope::tenant()
            : ProviderScope::company((int) $connection->company_id);
    }

    private function provenance(string $source, ProviderAdapter $provider, string $stream): WorkforceProvenance
    {
        return new WorkforceProvenance($source, correlationReference: $provider->descriptor()->id.':'.$stream);
    }

    private function nextCursor(WorkforcePage|WorkforceChangePage $page, ?string $previous): ?string
    {
        if (! $page->complete && $page->nextPageCursor === $previous) {
            throw new WorkforceSyncException('The adapter returned the same page cursor twice; refusing to loop.');
        }

        return $page->nextPageCursor;
    }

    /** @return array<string, int> */
    private function emptyTally(): array
    {
        return [
            'pages' => 0,
            'companies' => 0,
            'organizationUnits' => 0,
            'positions' => 0,
            'employees' => 0,
            'deactivations' => 0,
            'reactivations' => 0,
            'mergesQueued' => 0,
            'conflicts' => 0,
        ];
    }

    /** @param  array<string, int>  $tally */
    private function seen(array $tally): int
    {
        return $tally['companies'] + $tally['organizationUnits'] + $tally['positions'] + $tally['employees']
            + $tally['deactivations'] + $tally['mergesQueued'] + $tally['conflicts'];
    }

    /** @param  array<string, int>  $tally */
    private function report(int $connectionId, string $stream, string $pass, array $tally, int $version, \DateTimeImmutable $asOf): WorkforceSyncReport
    {
        return new WorkforceSyncReport(
            $connectionId,
            $stream,
            $pass,
            $tally['pages'],
            $tally['companies'],
            $tally['organizationUnits'],
            $tally['positions'],
            $tally['employees'],
            $tally['deactivations'],
            $tally['reactivations'],
            $tally['mergesQueued'],
            $tally['conflicts'],
            $version,
            $asOf,
        );
    }

    private static function reasonCode(\Throwable $refusal): string
    {
        return match (true) {
            $refusal instanceof WorkforceProjectionConflictException => 'projection_conflict',
            $refusal instanceof ExternalIdentityCollisionException => 'identity_collision',
            $refusal instanceof CompanyMoveRefusedException => 'company_move_refused',
            $refusal instanceof ConnectorRecordNotFoundException => 'record_not_found',
            default => 'history_conflict',
        };
    }

    private static function pageLimit(): int
    {
        $limit = config('people-connector.sync.page_limit', 250);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('people-connector.sync.page_limit must be between 1 and 1000.');
        }

        return $limit;
    }
}
