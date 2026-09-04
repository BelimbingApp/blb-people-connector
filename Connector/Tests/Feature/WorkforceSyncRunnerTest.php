<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceFreshness;
use App\Domains\PeopleConnector\Connector\Data\WorkforceMerge;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\StaleWorkforceStateException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper is prefixed syncRunner and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany().
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const SYNC_RUNNER_PROVIDER = 'test.sync';

function syncRunnerRef(WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference(SYNC_RUNNER_PROVIDER, $type, $id);
}

function syncRunnerAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

function syncRunnerCompany(string $id, string $name, DateTimeImmutable $at): WorkforceCompany
{
    return new WorkforceCompany(syncRunnerRef(WorkforceResourceType::Company, $id), $name, true, $at, code: strtoupper($id));
}

function syncRunnerUnit(string $id, string $company, string $name, DateTimeImmutable $at): WorkforceOrganizationUnit
{
    return new WorkforceOrganizationUnit(
        syncRunnerRef(WorkforceResourceType::OrganizationUnit, $id),
        syncRunnerRef(WorkforceResourceType::Company, $company),
        $name,
        true,
        $at,
        $at,
        code: strtoupper($id),
        kind: 'department',
    );
}

function syncRunnerPosition(string $id, string $company, string $unit, string $name, DateTimeImmutable $at): WorkforcePosition
{
    return new WorkforcePosition(
        syncRunnerRef(WorkforceResourceType::Position, $id),
        syncRunnerRef(WorkforceResourceType::Company, $company),
        $name,
        true,
        $at,
        $at,
        organizationReference: syncRunnerRef(WorkforceResourceType::OrganizationUnit, $unit),
        tier: 'T2',
    );
}

/** @param  array<string, mixed>  $overrides */
function syncRunnerEmployee(string $id, string $company, string $name, DateTimeImmutable $at, array $overrides = []): WorkforceEmployee
{
    return new WorkforceEmployee(
        syncRunnerRef(WorkforceResourceType::Employee, $id),
        syncRunnerRef(WorkforceResourceType::Company, $overrides['company'] ?? $company),
        $name,
        $overrides['active'] ?? true,
        $overrides['effectiveAt'] ?? $at,
        $at,
        employeeNumber: $overrides['employeeNumber'] ?? strtoupper($id),
        email: $overrides['email'] ?? strtolower($id).'@example.test',
        organizationReference: isset($overrides['unit']) ? syncRunnerRef(WorkforceResourceType::OrganizationUnit, $overrides['unit']) : null,
        positionReference: isset($overrides['position']) ? syncRunnerRef(WorkforceResourceType::Position, $overrides['position']) : null,
        managerReference: isset($overrides['manager']) ? syncRunnerRef(WorkforceResourceType::Employee, $overrides['manager']) : null,
        departmentHeadReference: isset($overrides['head']) ? syncRunnerRef(WorkforceResourceType::Employee, $overrides['head']) : null,
    );
}

/**
 * A tenant with a company, an active company-scoped connection for
 * SYNC_RUNNER_PROVIDER, and an authorization service that allows everything.
 *
 * @return array{int, int, int, Actor}
 */
function syncRunnerTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), SYNC_RUNNER_PROVIDER);
    $store->activate((int) $connection->id);

    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });

    return [
        (int) $tenant->id,
        (int) $company->id,
        (int) $connection->id,
        new Actor(PrincipalType::USER, 20, (int) $company->id, tenantId: (int) $tenant->id),
    ];
}

/**
 * An adapter whose source answers from scripted pages. Bootstrap pages are
 * keyed by the page cursor that requests them ('first' for the opening
 * request); change pages likewise. A page may be a Throwable, which the
 * source throws when that page is requested. Every request is recorded.
 *
 * @param  array<string, WorkforcePage|Throwable>  $bootstrapPages
 * @param  array<string, WorkforceChangePage|Throwable>  $changePages
 */
function syncRunnerProvider(array $bootstrapPages = [], array $changePages = [], string $id = SYNC_RUNNER_PROVIDER): ProviderAdapter
{
    return new class($bootstrapPages, $changePages, $id) implements ProviderAdapter, ResolvesProviderPorts
    {
        public object $source;

        public function __construct(array $bootstrapPages, array $changePages, private string $id)
        {
            $this->source = new class($bootstrapPages, $changePages) implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                /** @var list<array{string, ?string, ?string, int}> */
                public array $calls = [];

                public function __construct(private array $bootstrapPages, private array $changePages) {}

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    $this->calls[] = ['bootstrap', null, $request->pageCursor, $request->limit];

                    return $this->page($this->bootstrapPages, $request->pageCursor);
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    $this->calls[] = ['changes', $request->resumeCursor, $request->pageCursor, $request->limit];

                    return $this->page($this->changePages, $request->pageCursor);
                }

                private function page(array $pages, ?string $cursor): object
                {
                    $page = $pages[$cursor ?? 'first'] ?? throw new LogicException("No scripted page for cursor '{$cursor}'.");

                    if ($page instanceof Throwable) {
                        throw $page;
                    }

                    return $page;
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor($this->id, 'Sync Test Provider', '0.1.0', '1.0.0');
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                    new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return $this->source instanceof $contract ? $this->source : null;
        }
    };
}

function syncRunnerEmployeeRow(int $tenantId, string $externalId): ?WorkforceEmployeeProjection
{
    $identity = ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('resource_type', WorkforceResourceType::Employee->value)
        ->where('external_id', $externalId)
        ->first();

    if ($identity === null) {
        return null;
    }

    return WorkforceEmployeeProjection::query()
        ->withoutCompanyScope('The test addresses one projection by the entity its external id resolved to.')
        ->forTenant($tenantId)
        ->where('workforce_entity_id', $identity->workforce_entity_id)
        ->first();
}

function syncRunnerEntityFor(int $tenantId, WorkforceResourceType $type, string $externalId): ?WorkforceEntity
{
    $identity = ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('resource_type', $type->value)
        ->where('external_id', $externalId)
        ->first();

    return $identity === null ? null : WorkforceEntity::query()->forTenant($tenantId)->find($identity->workforce_entity_id);
}

/** @return list<array{kind: string, key: string, reason: ?string, severity: string}> */
function syncRunnerIssues(int $tenantId, int $connectionId): array
{
    return ReconciliationIssue::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->orderBy('id')
        ->get()
        ->map(static fn (ReconciliationIssue $issue): array => [
            'kind' => $issue->kind,
            'key' => $issue->issue_key,
            'reason' => $issue->details['reason_code'] ?? null,
            'severity' => $issue->severity,
        ])
        ->all();
}

/** A two-page bootstrap: company, unit, position and manager on page one; the report on page two. */
function syncRunnerBootstrapPages(DateTimeImmutable $at): array
{
    return [
        'first' => new WorkforcePage(
            [syncRunnerEmployee('emp-1', 'co-1', 'Mona Manager', $at, ['unit' => 'unit-1', 'position' => 'pos-1'])],
            $at,
            nextPageCursor: 'page-2',
            companies: [syncRunnerCompany('co-1', 'Sync Co', $at)],
            organizationUnits: [syncRunnerUnit('unit-1', 'co-1', 'Operations', $at)],
            positions: [syncRunnerPosition('pos-1', 'co-1', 'unit-1', 'Operator', $at)],
        ),
        'page-2' => new WorkforcePage(
            [syncRunnerEmployee('emp-2', 'co-1', 'Rae Report', $at, ['unit' => 'unit-1', 'position' => 'pos-1', 'manager' => 'emp-1', 'head' => 'emp-1'])],
            $at,
            resumeCursor: 'resume-after-bootstrap',
            complete: true,
        ),
    ];
}

test('a bootstrap projects every page, links the hierarchy, checkpoints once at the end, and is idempotent when repeated', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Bootstrap Tenant');
    $at = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $provider = syncRunnerProvider(syncRunnerBootstrapPages($at));
    $runner = app(WorkforceSyncRunner::class);

    $report = $runner->bootstrap($actor, $provider, $connectionId, limit: 50);

    expect($report->pass)->toBe('bootstrap')
        ->and($report->pages)->toBe(2)
        ->and([$report->companies, $report->organizationUnits, $report->positions, $report->employees])->toBe([1, 1, 1, 2])
        ->and($report->conflicts)->toBe(0)
        ->and($report->applied())->toBe(5)
        ->and($report->empty())->toBeFalse()
        ->and($report->checkpointVersion)->toBe(1)
        ->and($provider->source->calls)->toBe([
            ['bootstrap', null, null, 50],
            ['bootstrap', null, 'page-2', 50],
        ]);

    $manager = syncRunnerEmployeeRow($tenantId, 'emp-1');
    $report2 = syncRunnerEmployeeRow($tenantId, 'emp-2');
    $unit = syncRunnerEntityFor($tenantId, WorkforceResourceType::OrganizationUnit, 'unit-1');
    $position = syncRunnerEntityFor($tenantId, WorkforceResourceType::Position, 'pos-1');

    expect($manager)->not->toBeNull()
        ->and($report2)->not->toBeNull()
        ->and($report2->display_name)->toBe('Rae Report')
        ->and($report2->email)->toBe('emp-2@example.test')
        ->and((int) $report2->manager_entity_id)->toBe((int) $manager->workforce_entity_id)
        ->and((int) $report2->department_head_entity_id)->toBe((int) $manager->workforce_entity_id)
        ->and((int) $report2->organization_entity_id)->toBe((int) $unit->id)
        ->and((int) $report2->position_entity_id)->toBe((int) $position->id)
        ->and(WorkforceCompanyProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(1)
        ->and(WorkforceOrganizationUnitProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(1)
        ->and(WorkforcePositionProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(1);

    $checkpoint = SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->sole();
    expect($checkpoint->stream)->toBe('workforce')
        ->and($checkpoint->version)->toBe(1)
        ->and($checkpoint->resume_cursor)->toBe('resume-after-bootstrap')
        ->and($checkpoint->as_of_at->getTimestamp())->toBe($at->getTimestamp())
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([]);

    // Same pages again: nothing new is written and the checkpoint does not move.
    $again = $runner->bootstrap($actor, $provider, $connectionId, limit: 50);

    expect($again->applied())->toBe(5)
        ->and($again->checkpointVersion)->toBe(1)
        ->and(WorkforceEmployeeProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(2)
        ->and(WorkforceEntity::query()->forTenant($tenantId)->count())->toBe(5)
        ->and(SyncCheckpointEvent::query()->forTenant($tenantId)->count())->toBe(1)
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([]);
});

test('a bootstrap that fails on a later page keeps the rows it applied and leaves no checkpoint, so the retry starts over', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Partial Tenant');
    $at = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $pages = syncRunnerBootstrapPages($at);
    $broken = $pages;
    $broken['page-2'] = new RuntimeException('provider went away mid-bootstrap');
    $runner = app(WorkforceSyncRunner::class);

    expect(fn () => $runner->bootstrap($actor, syncRunnerProvider($broken), $connectionId))
        ->toThrow(RuntimeException::class, 'went away');

    expect(syncRunnerEmployeeRow($tenantId, 'emp-1'))->not->toBeNull()
        ->and(syncRunnerEmployeeRow($tenantId, 'emp-2'))->toBeNull()
        ->and(SyncCheckpoint::query()->forTenant($tenantId)->count())->toBe(0)
        ->and(app(WorkforceFreshnessPolicy::class)->for($connectionId, $at)->staleReason)->toBe(WorkforceFreshness::REASON_NEVER_SYNCHRONIZED);

    $healed = syncRunnerProvider($pages);
    $report = $runner->bootstrap($actor, $healed, $connectionId);

    expect($healed->source->calls[0])->toBe(['bootstrap', null, null, 250])
        ->and($report->employees)->toBe(2)
        ->and(syncRunnerEmployeeRow($tenantId, 'emp-2'))->not->toBeNull()
        ->and(SyncCheckpoint::query()->forTenant($tenantId)->sole()->version)->toBe(1);
});

test('an incremental pass is refused before any bootstrap has completed, and the adapter is never asked', function (): void {
    [, , $connectionId, $actor] = syncRunnerTenant('Sync Unbootstrapped Tenant');
    $provider = syncRunnerProvider();

    expect(fn () => app(WorkforceSyncRunner::class)->incremental($actor, $provider, $connectionId))
        ->toThrow(WorkforceSyncException::class, 'run the bootstrap pass first');

    expect($provider->source->calls)->toBe([]);
});

test('the runner refuses a connection that belongs to a different provider or is inactive', function (): void {
    [$tenantId, $companyId, $connectionId, $actor] = syncRunnerTenant('Sync Mismatch Tenant');
    $other = syncRunnerProvider(syncRunnerBootstrapPages(syncRunnerAt('2026-09-01T08:00:00+00:00')), id: 'other.provider');

    expect(fn () => app(WorkforceSyncRunner::class)->bootstrap($actor, $other, $connectionId))
        ->toThrow(WorkforceSyncException::class, "belongs to provider 'test.sync', not 'other.provider'");

    expect($other->source->calls)->toBe([])
        ->and(SyncCheckpoint::query()->forTenant($tenantId)->count())->toBe(0);
});

test('an incremental pass resumes from the checkpoint, keeps identity across a name and email change, retires a deactivation, and queues a refusal for an unknown reference', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Incremental Tenant');
    $t0 = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $t1 = syncRunnerAt('2026-09-02T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, syncRunnerProvider(syncRunnerBootstrapPages($t0)), $connectionId);
    $before = syncRunnerEmployeeRow($tenantId, 'emp-2');

    $provider = syncRunnerProvider(changePages: [
        'first' => new WorkforceChangePage([
            new WorkforceUpsert(syncRunnerEmployee('emp-2', 'co-1', 'Rae Renamed', $t1, ['email' => 'rae.renamed@example.test', 'unit' => 'unit-1', 'position' => 'pos-1', 'manager' => 'emp-1']), $t1),
            new WorkforceUpsert(syncRunnerEmployee('emp-3', 'co-1', 'Nu Hire', $t1, ['unit' => 'unit-1']), $t1),
        ], $t1, nextPageCursor: 'page-2'),
        'page-2' => new WorkforceChangePage([
            new WorkforceDeactivation(syncRunnerRef(WorkforceResourceType::Employee, 'emp-1'), $t1),
            new WorkforceDeactivation(syncRunnerRef(WorkforceResourceType::Employee, 'never-seen'), $t1),
        ], $t1, resumeCursor: 'resume-after-day-2', complete: true),
    ]);

    $report = $runner->incremental($actor, $provider, $connectionId, limit: 100);

    expect($provider->source->calls)->toBe([
        ['changes', 'resume-after-bootstrap', null, 100],
        ['changes', 'resume-after-bootstrap', 'page-2', 100],
    ])
        ->and($report->pass)->toBe('incremental')
        ->and([$report->employees, $report->deactivations, $report->conflicts, $report->mergesQueued])->toBe([2, 1, 1, 0])
        ->and($report->checkpointVersion)->toBe(2);

    $after = syncRunnerEmployeeRow($tenantId, 'emp-2');
    $manager = syncRunnerEntityFor($tenantId, WorkforceResourceType::Employee, 'emp-1');

    expect((int) $after->workforce_entity_id)->toBe((int) $before->workforce_entity_id)
        ->and($after->display_name)->toBe('Rae Renamed')
        ->and($after->email)->toBe('rae.renamed@example.test')
        ->and(WorkforceEmployeeProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(3)
        ->and(syncRunnerEmployeeRow($tenantId, 'emp-3')?->display_name)->toBe('Nu Hire')
        ->and($manager->state)->toBe(WorkforceEntity::STATE_INACTIVE)
        ->and($manager->deactivated_at->getTimestamp())->toBe($t1->getTimestamp())
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([
            ['kind' => 'sync_conflict', 'key' => 'sync:employee:never-seen', 'reason' => 'record_not_found', 'severity' => 'error'],
        ]);

    $checkpoint = SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->sole();
    expect($checkpoint->version)->toBe(2)
        ->and($checkpoint->resume_cursor)->toBe('resume-after-day-2')
        ->and(SyncCheckpointEvent::query()->forTenant($tenantId)->pluck('version')->all())->toBe([1, 2]);
});

test('an identifier observed under two companies at the same instant fails closed into a reconciliation issue while the pass completes', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Collision Tenant');
    $at = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $provider = syncRunnerProvider([
        'first' => new WorkforcePage(
            [
                syncRunnerEmployee('emp-9', 'co-1', 'Same Id', $at),
                syncRunnerEmployee('emp-9', 'co-2', 'Same Id', $at),
            ],
            $at,
            resumeCursor: 'resume-1',
            complete: true,
            companies: [syncRunnerCompany('co-1', 'Sync Co', $at), syncRunnerCompany('co-2', 'Other Co', $at)],
        ),
    ]);

    $report = app(WorkforceSyncRunner::class)->bootstrap($actor, $provider, $connectionId);
    $row = syncRunnerEmployeeRow($tenantId, 'emp-9');
    $firstCompany = syncRunnerEntityFor($tenantId, WorkforceResourceType::Company, 'co-1');

    expect([$report->employees, $report->conflicts])->toBe([1, 1])
        ->and((int) $row->company_entity_id)->toBe((int) $firstCompany->id)
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([
            ['kind' => 'sync_conflict', 'key' => 'sync:employee:emp-9', 'reason' => 'projection_conflict', 'severity' => 'error'],
        ])
        ->and($report->checkpointVersion)->toBe(1);
});

test('a merge arriving in the feed is queued for review, not applied', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Merge Tenant');
    $t0 = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $t1 = syncRunnerAt('2026-09-02T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, syncRunnerProvider(syncRunnerBootstrapPages($t0)), $connectionId);

    $report = $runner->incremental($actor, syncRunnerProvider(changePages: [
        'first' => new WorkforceChangePage([
            new WorkforceMerge(
                syncRunnerRef(WorkforceResourceType::Employee, 'emp-2'),
                syncRunnerRef(WorkforceResourceType::Employee, 'emp-1'),
                $t1,
            ),
        ], $t1, resumeCursor: 'resume-2', complete: true),
    ]), $connectionId);

    $superseded = syncRunnerEntityFor($tenantId, WorkforceResourceType::Employee, 'emp-2');
    $surviving = syncRunnerEntityFor($tenantId, WorkforceResourceType::Employee, 'emp-1');

    expect([$report->mergesQueued, $report->conflicts, $report->employees])->toBe([1, 0, 0])
        ->and($superseded->state)->toBe(WorkforceEntity::STATE_ACTIVE)
        ->and($superseded->merged_into_entity_id)->toBeNull()
        ->and((int) $superseded->id)->not->toBe((int) $surviving->id)
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([
            ['kind' => 'sync_merge_requested', 'key' => 'sync:merge:employee:emp-2', 'reason' => 'review_required', 'severity' => 'warning'],
        ])
        ->and(ReconciliationIssue::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->sole()
            ->details['related_external_id'])->toBe('emp-1')
        ->and($report->checkpointVersion)->toBe(2);
});

test('a re-hire arrives as an active upsert for a deactivated reference and is reactivated onto the same entity', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Rehire Tenant');
    $t0 = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $t1 = syncRunnerAt('2026-09-02T08:00:00+00:00');
    $t2 = syncRunnerAt('2026-09-03T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, syncRunnerProvider(syncRunnerBootstrapPages($t0)), $connectionId);
    $original = syncRunnerEmployeeRow($tenantId, 'emp-2');

    $runner->incremental($actor, syncRunnerProvider(changePages: [
        'first' => new WorkforceChangePage([
            new WorkforceDeactivation(syncRunnerRef(WorkforceResourceType::Employee, 'emp-2'), $t1),
        ], $t1, resumeCursor: 'resume-2', complete: true),
    ]), $connectionId);

    expect(syncRunnerEntityFor($tenantId, WorkforceResourceType::Employee, 'emp-2')->state)->toBe(WorkforceEntity::STATE_INACTIVE);

    $report = $runner->incremental($actor, syncRunnerProvider(changePages: [
        'first' => new WorkforceChangePage([
            new WorkforceUpsert(syncRunnerEmployee('emp-2', 'co-1', 'Rae Returned', $t2, ['unit' => 'unit-1']), $t2),
        ], $t2, resumeCursor: 'resume-3', complete: true),
    ]), $connectionId);

    $entity = syncRunnerEntityFor($tenantId, WorkforceResourceType::Employee, 'emp-2');
    $row = syncRunnerEmployeeRow($tenantId, 'emp-2');

    expect([$report->reactivations, $report->employees, $report->conflicts])->toBe([1, 1, 0])
        ->and((int) $entity->id)->toBe((int) $original->workforce_entity_id)
        ->and($entity->state)->toBe(WorkforceEntity::STATE_ACTIVE)
        ->and($row->display_name)->toBe('Rae Returned')
        ->and($row->active)->toBeTrue()
        ->and(WorkforceEmployeeProjection::query()->withoutCompanyScope('Row count of the whole tenant is the point.')->forTenant($tenantId)->count())->toBe(2);
});

test('a bootstrap that completes with no records at all is reported as an issue rather than as a clean sync', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Empty Tenant');
    $at = syncRunnerAt('2026-09-01T08:00:00+00:00');

    $report = app(WorkforceSyncRunner::class)->bootstrap($actor, syncRunnerProvider([
        'first' => new WorkforcePage([], $at, resumeCursor: 'resume-empty', complete: true),
    ]), $connectionId);

    expect($report->empty())->toBeTrue()
        ->and($report->checkpointVersion)->toBe(1)
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([
            ['kind' => 'sync_empty_bootstrap', 'key' => 'sync:bootstrap:empty', 'reason' => 'no_records', 'severity' => 'warning'],
        ]);
});

test('freshness is decided from the provider watermark on the checkpoint and fails closed when stale, never synchronised, or inactive', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Freshness Tenant');
    [$otherTenant] = createTenantWithCompany(['name' => 'Sync Freshness Other Tenant']);
    $asOf = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $policy = app(WorkforceFreshnessPolicy::class);

    $never = $policy->for($connectionId, $asOf);
    expect($never->isStale())->toBeTrue()
        ->and($never->staleReason)->toBe(WorkforceFreshness::REASON_NEVER_SYNCHRONIZED)
        ->and($never->asOf)->toBeNull()
        ->and($never->ageMinutes())->toBeNull()
        ->and(fn () => $policy->assertFresh($connectionId, $asOf))->toThrow(StaleWorkforceStateException::class, 'never been synchronised');

    app(WorkforceSyncRunner::class)->bootstrap($actor, syncRunnerProvider(syncRunnerBootstrapPages($asOf)), $connectionId);

    $fresh = $policy->assertFresh($connectionId, $asOf->modify('+1 hour'));
    expect($fresh->isStale())->toBeFalse()
        ->and($fresh->ageMinutes())->toBe(60)
        ->and($fresh->maxAgeMinutes)->toBe(1440)
        ->and($fresh->asOf->getTimestamp())->toBe($asOf->getTimestamp());

    $stale = $policy->for($connectionId, $asOf->modify('+25 hours'));
    expect($stale->staleReason)->toBe(WorkforceFreshness::REASON_EXCEEDED_MAX_AGE)
        ->and($stale->ageMinutes())->toBe(1500)
        ->and(fn () => $stale->assertFresh())->toThrow(StaleWorkforceStateException::class, '1500 minutes old; the maximum is 1440');

    // The threshold is the configuration, not a constant: an hour-old watermark is stale once the maximum is 30 minutes.
    config()->set('people-connector.sync.max_age_minutes', 30);
    expect($policy->for($connectionId, $asOf->modify('+1 hour'))->staleReason)->toBe(WorkforceFreshness::REASON_EXCEEDED_MAX_AGE)
        ->and($policy->for($connectionId, $asOf->modify('+29 minutes'))->isStale())->toBeFalse();
    config()->set('people-connector.sync.max_age_minutes', 1440);

    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => $policy->for($connectionId, $asOf))->toThrow(ConnectorRecordNotFoundException::class);
});

test('a bootstrap whose every record is refused raises a feed-refused issue and leaves no checkpoint, so it cannot pass for a clean sync', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync All Refused Tenant');
    $at = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $foreign = static fn (string $id): ExternalReference => new ExternalReference('other.provider', WorkforceResourceType::Employee, $id);
    $foreignCompany = new ExternalReference('other.provider', WorkforceResourceType::Company, 'co-x');

    $report = app(WorkforceSyncRunner::class)->bootstrap($actor, syncRunnerProvider([
        'first' => new WorkforcePage(
            [
                new WorkforceEmployee($foreign('x-1'), $foreignCompany, 'Wrong Provider One', true, $at, $at),
                new WorkforceEmployee($foreign('x-2'), $foreignCompany, 'Wrong Provider Two', true, $at, $at),
            ],
            $at,
            resumeCursor: 'resume-refused',
            complete: true,
        ),
    ]), $connectionId);

    expect([$report->employees, $report->conflicts])->toBe([0, 2])
        ->and($report->feedRefused())->toBeTrue()
        ->and($report->checkpointAdvanced)->toBeFalse()
        ->and($report->checkpointVersion)->toBe(0)
        ->and(SyncCheckpoint::query()->forTenant($tenantId)->count())->toBe(0)
        ->and(app(WorkforceFreshnessPolicy::class)->for($connectionId, $at)->staleReason)->toBe(WorkforceFreshness::REASON_NEVER_SYNCHRONIZED)
        ->and(syncRunnerIssues($tenantId, $connectionId))->toBe([
            ['kind' => 'sync_conflict', 'key' => 'sync:employee:x-1', 'reason' => 'identity_collision', 'severity' => 'error'],
            ['kind' => 'sync_conflict', 'key' => 'sync:employee:x-2', 'reason' => 'identity_collision', 'severity' => 'error'],
            ['kind' => 'sync_feed_refused', 'key' => 'sync:feed:refused', 'reason' => 'every_record_refused', 'severity' => 'error'],
        ]);
});

test('an incremental pass whose every change is refused keeps the previous checkpoint so the same pages are presented again', function (): void {
    [$tenantId, , $connectionId, $actor] = syncRunnerTenant('Sync Incremental Refused Tenant');
    $t0 = syncRunnerAt('2026-09-01T08:00:00+00:00');
    $t1 = syncRunnerAt('2026-09-02T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, syncRunnerProvider(syncRunnerBootstrapPages($t0)), $connectionId);

    $report = $runner->incremental($actor, syncRunnerProvider(changePages: [
        'first' => new WorkforceChangePage([
            new WorkforceDeactivation(syncRunnerRef(WorkforceResourceType::Employee, 'ghost-1'), $t1),
            new WorkforceDeactivation(syncRunnerRef(WorkforceResourceType::Employee, 'ghost-2'), $t1),
        ], $t1, resumeCursor: 'resume-must-not-land', complete: true),
    ]), $connectionId);

    $checkpoint = SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->sole();

    expect([$report->deactivations, $report->conflicts])->toBe([0, 2])
        ->and($report->feedRefused())->toBeTrue()
        ->and($report->checkpointVersion)->toBe(1)
        ->and($checkpoint->version)->toBe(1)
        ->and($checkpoint->resume_cursor)->toBe('resume-after-bootstrap')
        ->and(SyncCheckpointEvent::query()->forTenant($tenantId)->count())->toBe(1)
        ->and(collect(syncRunnerIssues($tenantId, $connectionId))->pluck('kind')->all())->toBe(['sync_conflict', 'sync_conflict', 'sync_feed_refused'])
        ->and(app(WorkforceFreshnessPolicy::class)->for($connectionId, $t1)->isStale())->toBeFalse();
});
