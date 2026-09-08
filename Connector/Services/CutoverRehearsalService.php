<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CutoverCountRow;
use App\Domains\PeopleConnector\Connector\Data\CutoverRehearsalReport;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * The projections whose totals a cutover has to reconcile, in report order.
     *
     * Companies are not counted: they are the axis every other count is
     * grouped by, and a tenant whose companies themselves differ between the
     * two providers is a different problem from a cutover being short a
     * position.
     *
     * @var list<array{0: WorkforceResourceType, 1: class-string<Model>}>
     */
    private const COUNTED_RESOURCES = [
        [WorkforceResourceType::Employee, WorkforceEmployeeProjection::class],
        [WorkforceResourceType::OrganizationUnit, WorkforceOrganizationUnitProjection::class],
        [WorkforceResourceType::Position, WorkforcePositionProjection::class],
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly WorkforceFreshnessPolicy $freshness,
        private readonly OperatorAuditLog $audit,
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

        $report = new CutoverRehearsalReport(
            fromConnectionId: $fromConnectionId,
            toConnectionId: $toConnectionId,
            unmappedIdentities: $this->unmappedIdentities($tenantId, $fromConnectionId, $toConnectionId),
            targetStale: $freshness->isStale(),
            targetStaleReason: $freshness->staleReason,
            openIssues: $this->openIssues($tenantId, $fromConnectionId, $toConnectionId),
            counts: $this->counts($tenantId, $fromConnectionId, $toConnectionId),
        );

        // A rehearsal is a read of one tenant's sensitive workforce state; the
        // read itself is what plan 0001 asks to record. This row is the only
        // thing a rehearsal writes.
        $this->audit->record(
            $actor,
            OperatorAuditOperation::CutoverRehearsed,
            $fromConnectionId,
            $toConnectionId,
            null,
            [
                'unmapped_identities' => $report->unmappedIdentities,
                'target_stale' => $report->targetStale,
                'open_issues' => $report->openIssues,
                'count_mismatches' => $report->countMismatches(),
            ],
            ['blocked' => $report->blocked(), 'blockers' => $report->blockers()],
        );

        return $report;
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
     * What each side would hand over, per company and resource type.
     *
     * A projection belongs to a side when the entity behind it still has a
     * live identity on that connection — `effective_to` null and nothing has
     * replaced it. That is the same question the switch itself will ask, so a
     * row counted here is a row the target will actually be able to answer
     * for, and a row missing here is one it will not.
     *
     * Two queries per resource type, one per side, each grouped in the
     * database. Counting per company in PHP would have been a query per
     * company, and a tenant with thirty companies is exactly the tenant whose
     * cutover most needs rehearsing.
     *
     * @return list<CutoverCountRow>
     */
    private function counts(int $tenantId, int $fromConnectionId, int $toConnectionId): array
    {
        $rows = [];

        foreach (self::COUNTED_RESOURCES as [$resourceType, $model]) {
            $source = $this->activeProjectionsByCompany(
                $tenantId, $model, $this->entitiesTheSourceStillAnswersFor($tenantId, $fromConnectionId, $toConnectionId),
            );
            $target = $this->activeProjectionsByCompany(
                $tenantId, $model, $this->entitiesLiveOnConnection($tenantId, $toConnectionId),
            );

            $companyEntityIds = array_keys($source + $target);
            sort($companyEntityIds);

            foreach ($companyEntityIds as $companyEntityId) {
                $rows[] = new CutoverCountRow(
                    companyEntityId: $companyEntityId,
                    resourceType: $resourceType,
                    sourceCount: $source[$companyEntityId] ?? 0,
                    targetCount: $target[$companyEntityId] ?? 0,
                );
            }
        }

        return $rows;
    }

    /**
     * The entities one connection currently speaks for.
     *
     * @return Builder<ExternalIdentity>
     */
    private function entitiesLiveOnConnection(int $tenantId, int $connectionId): Builder
    {
        return ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->whereNull('effective_to')
            ->whereNull('replaced_by_identity_id')
            ->select('workforce_entity_id');
    }

    /**
     * The entities the source still answers for, handovers included.
     *
     * A remap is what retires a source identity, so "still live on the source"
     * alone would read a fully mapped tenant as a source holding nobody — and
     * a fully mapped tenant is precisely when an operator runs the rehearsal.
     * Every count would then disagree at the one moment the cutover is ready.
     *
     * So an identity counts for the source when it is still live there, or
     * when it was handed to *this* target by a remap. The second clause names
     * the target explicitly rather than accepting any replacement: an identity
     * remapped onto some third connection is not something this cutover is
     * about to move.
     *
     * @return Builder<ExternalIdentity>
     */
    private function entitiesTheSourceStillAnswersFor(int $tenantId, int $fromConnectionId, int $toConnectionId): Builder
    {
        $targetIdentityIds = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $toConnectionId)
            ->select('id');

        return ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('connection_id', $fromConnectionId)
            ->where(function (Builder $query) use ($targetIdentityIds): void {
                $query
                    ->where(fn (Builder $live): Builder => $live
                        ->whereNull('effective_to')
                        ->whereNull('replaced_by_identity_id'))
                    ->orWhereIn('replaced_by_identity_id', $targetIdentityIds);
            })
            ->select('workforce_entity_id');
    }

    /**
     * Live projections of one kind for a set of entities, counted per company.
     *
     * Deactivated and privacy-deleted rows are excluded on both sides, so a
     * person erased under the retention policy does not read as a target that
     * lost somebody.
     *
     * @param  class-string<Model>  $model
     * @param  Builder<ExternalIdentity>  $entities
     * @return array<int, int> company workforce entity id => count
     */
    private function activeProjectionsByCompany(int $tenantId, string $model, Builder $entities): array
    {
        return $model::query()
            ->withoutCompanyScope(
                'A cutover rehearsal reconciles every company in the tenant at once; the per-company '
                .'split is the answer it reports, so pinning one company would be pinning the answer.',
            )
            ->forTenant($tenantId)
            ->where('active', true)
            ->whereNull('privacy_deleted_at')
            ->whereIn('workforce_entity_id', $entities)
            ->groupBy('company_entity_id')
            ->selectRaw('company_entity_id, count(*) as projection_count')
            ->pluck('projection_count', 'company_entity_id')
            ->mapWithKeys(static fn (mixed $count, mixed $company): array => [(int) $company => (int) $count])
            ->all();
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
