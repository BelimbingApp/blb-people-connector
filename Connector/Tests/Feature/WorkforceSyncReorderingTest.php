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
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;

/*
 * Out-of-order changes on the incremental pass (#273). Self-contained: every
 * helper is prefixed reorder and lives here, so the file passes or fails alone
 * for its own reasons. The only outside helper is the platform's
 * createTenantWithCompany().
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const REORDER_PROVIDER = 'test.reorder';

function reorderRef(WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference(REORDER_PROVIDER, $type, $id);
}

function reorderAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

function reorderEmployee(string $id, DateTimeImmutable $at, bool $active = true, ?string $name = null): WorkforceEmployee
{
    return new WorkforceEmployee(
        reorderRef(WorkforceResourceType::Employee, $id),
        reorderRef(WorkforceResourceType::Company, 'co-1'),
        $name ?? ucfirst($id),
        $active,
        $at,
        $at,
        employeeNumber: strtoupper($id),
        email: strtolower($id).'@example.test',
    );
}

/** @return array{int, int, Actor} */
function reorderTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), REORDER_PROVIDER);
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
        (int) $connection->id,
        new Actor(PrincipalType::USER, 7002, (int) $company->id, tenantId: (int) $tenant->id),
    ];
}

function reorderProvider(array $bootstrapPages, array $changePages): ProviderAdapter
{
    return new class($bootstrapPages, $changePages) implements ProviderAdapter, ResolvesProviderPorts
    {
        public object $source;

        public function __construct(array $bootstrapPages, array $changePages)
        {
            $this->source = new class($bootstrapPages, $changePages) implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                public function __construct(private array $bootstrapPages, private array $changePages) {}

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    return $this->bootstrapPages[$request->pageCursor ?? 'first']
                        ?? throw new LogicException("No scripted bootstrap page for '{$request->pageCursor}'.");
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    $key = $request->pageCursor ?? $request->resumeCursor ?? 'first';

                    return $this->changePages[$key]
                        ?? throw new LogicException("No scripted change page for '{$key}'.");
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(REORDER_PROVIDER, 'Reorder Test Provider', '0.1.0', '1.0.0');
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
            return new ProviderHealth(ProviderHealthState::Healthy, reorderAt('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return $this->source instanceof $contract ? $this->source : null;
        }
    };
}

function reorderIdentity(int $tenantId, string $externalId): ExternalIdentity
{
    return ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('provider_id', REORDER_PROVIDER)
        ->where('external_id', $externalId)
        ->firstOrFail();
}

function reorderEntity(int $tenantId, string $externalId): WorkforceEntity
{
    return WorkforceEntity::query()
        ->forTenant($tenantId)
        ->whereKey(reorderIdentity($tenantId, $externalId)->workforce_entity_id)
        ->firstOrFail();
}

function reorderProjection(int $tenantId, string $externalId): WorkforceEmployeeProjection
{
    return WorkforceEmployeeProjection::query()
        ->forTenant($tenantId)
        ->withoutCompanyScope('the projection is looked up by the entity the identity resolves to, across the whole tenant')
        ->where('workforce_entity_id', reorderIdentity($tenantId, $externalId)->workforce_entity_id)
        ->firstOrFail();
}

function reorderConflicts(int $tenantId, string $externalId): Collection
{
    return ReconciliationIssue::query()
        ->forTenant($tenantId)
        ->where('kind', WorkforceSyncRunner::ISSUE_KIND_CONFLICT)
        ->where('external_id', $externalId)
        ->get();
}

function reorderCheckpointVersion(int $tenantId, int $connectionId): int
{
    return (int) SyncCheckpoint::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->where('stream', WorkforceFreshnessPolicy::stream())
        ->value('version');
}

/**
 * A tenant's whole workforce footprint, for proving another tenant's pass left
 * it alone: every projection row, every identity row, and the issue count.
 *
 * @return array{list<array<string, mixed>>, list<array<string, mixed>>, int}
 */
function reorderFootprint(int $tenantId): array
{
    return [
        WorkforceEmployeeProjection::query()->forTenant($tenantId)
            ->withoutCompanyScope('the footprint is every projection in the tenant')
            ->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all(),
        ExternalIdentity::query()->forTenant($tenantId)->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all(),
        ReconciliationIssue::query()->forTenant($tenantId)->count(),
    ];
}

const REORDER_BOOTSTRAP_AT = '2026-09-03T08:00:00+00:00';
const REORDER_STALE_AT = '2026-09-01T08:00:00+00:00';
const REORDER_FRESH_AT = '2026-09-04T08:00:00+00:00';

/**
 * Bootstrap emp-1 and emp-2 at REORDER_BOOTSTRAP_AT, so any change dated
 * REORDER_STALE_AT is older than what is on file, then run one incremental
 * pass over $changes. The same page serves a replay from version 1, and
 * $again is what the next incremental pass reads.
 *
 * @param  list<WorkforceUpsert|WorkforceDeactivation>  $changes
 * @param  list<WorkforceUpsert|WorkforceDeactivation>  $again
 * @return array{int, int, Actor, ProviderAdapter}
 */
function reorderHistory(string $name, array $changes, array $again = []): array
{
    [$tenantId, $connectionId, $actor] = reorderTenant($name);
    $bootstrapAt = reorderAt(REORDER_BOOTSTRAP_AT);
    $freshAt = reorderAt(REORDER_FRESH_AT);

    $provider = reorderProvider(
        [
            'first' => new WorkforcePage(
                [reorderEmployee('emp-1', $bootstrapAt), reorderEmployee('emp-2', $bootstrapAt)],
                $bootstrapAt,
                resumeCursor: 'after-bootstrap',
                complete: true,
                companies: [new WorkforceCompany(reorderRef(WorkforceResourceType::Company, 'co-1'), 'Reorder Co', true, $bootstrapAt)],
            ),
        ],
        [
            'after-bootstrap' => new WorkforceChangePage($changes, $freshAt, resumeCursor: 'again', complete: true),
            'again' => new WorkforceChangePage($again, $freshAt, resumeCursor: 'done', complete: true),
            'done' => new WorkforceChangePage([], $freshAt, resumeCursor: 'done', complete: true),
        ],
    );

    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, $provider, $connectionId);
    $runner->incremental($actor, $provider, $connectionId);

    return [$tenantId, $connectionId, $actor, $provider];
}

function reorderStaleUpsert(string $name = 'Emp One Stale', bool $active = true): WorkforceUpsert
{
    $staleAt = reorderAt(REORDER_STALE_AT);

    return new WorkforceUpsert(reorderEmployee('emp-1', $staleAt, $active, $name), $staleAt);
}

function reorderStaleDeactivation(): WorkforceDeactivation
{
    return new WorkforceDeactivation(reorderRef(WorkforceResourceType::Employee, 'emp-1'), reorderAt(REORDER_STALE_AT));
}

test('an older upsert on the incremental pass leaves the projection byte-identical', function (): void {
    [$tenantId, $connectionId, $actor] = reorderTenant('Reorder Intact Tenant');
    $bootstrapAt = reorderAt(REORDER_BOOTSTRAP_AT);
    $provider = reorderProvider(
        [
            'first' => new WorkforcePage(
                [reorderEmployee('emp-1', $bootstrapAt)],
                $bootstrapAt,
                resumeCursor: 'after-bootstrap',
                complete: true,
                companies: [new WorkforceCompany(reorderRef(WorkforceResourceType::Company, 'co-1'), 'Reorder Co', true, $bootstrapAt)],
            ),
        ],
        [
            'after-bootstrap' => new WorkforceChangePage([reorderStaleUpsert()], reorderAt(REORDER_FRESH_AT), resumeCursor: 'done', complete: true),
        ],
    );
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, $provider, $connectionId);
    $before = reorderProjection($tenantId, 'emp-1')->getAttributes();

    $runner->incremental($actor, $provider, $connectionId);

    expect(reorderProjection($tenantId, 'emp-1')->getAttributes())->toBe($before)
        ->and(reorderProjection($tenantId, 'emp-1')->display_name)->toBe('Emp-1');
});

test('an older inactive upsert on the incremental pass leaves the entity active', function (): void {
    [$tenantId] = reorderHistory('Reorder Entity Tenant', [reorderStaleUpsert(active: false)]);

    expect(reorderEntity($tenantId, 'emp-1')->state)->toBe(WorkforceEntity::STATE_ACTIVE)
        ->and(reorderEntity($tenantId, 'emp-1')->deactivated_at)->toBeNull()
        ->and(reorderIdentity($tenantId, 'emp-1')->state)->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('an older upsert on the incremental pass is reported as superseded, not as an employee applied', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = reorderHistory('Reorder Tally Tenant', []);
    $version = reorderCheckpointVersion($tenantId, $connectionId);

    // The 'again' page is what this pass reads; the fixture's first pass has
    // already consumed 'after-bootstrap'.
    $provider->source = reorderProvider([], [
        'again' => new WorkforceChangePage([reorderStaleUpsert()], reorderAt(REORDER_FRESH_AT), resumeCursor: 'done', complete: true),
    ])->source;
    $report = app(WorkforceSyncRunner::class)->incremental($actor, $provider, $connectionId);

    // Nothing was written, so the report must not say something was: the
    // operator reading "1 employee upserted" would look for a change that never
    // happened. The pass itself was fine, so the checkpoint still advances.
    expect($report->superseded)->toBe(1)
        ->and($report->employees)->toBe(0)
        ->and($report->conflicts)->toBe(0)
        ->and($report->checkpointAdvanced)->toBeTrue()
        ->and(reorderCheckpointVersion($tenantId, $connectionId))->toBe($version + 1);
});

test('an older deactivation on the incremental pass is refused and queued as a sync conflict', function (): void {
    [$tenantId] = reorderHistory('Reorder Deactivation Tenant', [reorderStaleDeactivation()]);
    $conflicts = reorderConflicts($tenantId, 'emp-1');

    // The provider contradicted itself: it says emp-1 left before the fact it
    // sent last. That is an anomaly for an operator, not a silent skip.
    expect(reorderIdentity($tenantId, 'emp-1')->state)->toBe(ExternalIdentity::STATE_ACTIVE)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts->first()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($conflicts->first()->details['reason_code'])->toBe('identity_collision');
});

test('the same older deactivation arriving again lands on the open conflict rather than a second one', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = reorderHistory(
        'Reorder Repeat Tenant',
        [reorderStaleDeactivation()],
        again: [reorderStaleDeactivation()],
    );
    $issues = ReconciliationIssue::query()->forTenant($tenantId)->count();
    expect(reorderConflicts($tenantId, 'emp-1'))->toHaveCount(1);

    app(WorkforceSyncRunner::class)->incremental($actor, $provider, $connectionId);

    expect(reorderConflicts($tenantId, 'emp-1'))->toHaveCount(1)
        ->and(reorderConflicts($tenantId, 'emp-1')->first()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and(ReconciliationIssue::query()->forTenant($tenantId)->count())->toBe($issues)
        ->and(reorderIdentity($tenantId, 'emp-1')->state)->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('a replay of the same out-of-order page skips both changes as superseded and raises nothing', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = reorderHistory(
        'Reorder Replay Tenant',
        [reorderStaleUpsert(), reorderStaleDeactivation()],
    );
    $issues = ReconciliationIssue::query()->forTenant($tenantId)->count();
    $projection = reorderProjection($tenantId, 'emp-1')->getAttributes();

    // The incremental pass queued the deactivation as a conflict. The replay
    // reads the same page and must not: that is the documented asymmetry.
    $report = app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect($report->superseded)->toBe(2)
        ->and($report->conflicts)->toBe(0)
        ->and($report->employees)->toBe(0)
        ->and(ReconciliationIssue::query()->forTenant($tenantId)->count())->toBe($issues)
        ->and(reorderProjection($tenantId, 'emp-1')->getAttributes())->toBe($projection)
        ->and(reorderIdentity($tenantId, 'emp-1')->state)->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('two upserts for one reference in reverse order on one page: the later-observed record wins', function (): void {
    $newestAt = reorderAt('2026-09-06T08:00:00+00:00');
    $middleAt = reorderAt('2026-09-05T08:00:00+00:00');
    [$tenantId] = reorderHistory('Reorder Page Order Tenant', [
        new WorkforceUpsert(reorderEmployee('emp-1', $newestAt, name: 'Emp One Newest'), $newestAt),
        new WorkforceUpsert(reorderEmployee('emp-1', $middleAt, name: 'Emp One Middle'), $middleAt),
    ]);
    $projection = reorderProjection($tenantId, 'emp-1');

    // Page position is not chronology. The record the provider observed last
    // is the current fact whether it came first or second on the page.
    expect($projection->display_name)->toBe('Emp One Newest')
        ->and($projection->observed_at->getTimestamp())->toBe($newestAt->getTimestamp());
});

test('the reverse-order page reports one employee applied and one superseded', function (): void {
    $newestAt = reorderAt('2026-09-06T08:00:00+00:00');
    $middleAt = reorderAt('2026-09-05T08:00:00+00:00');
    [, $connectionId, $actor, $provider] = reorderHistory('Reorder Page Tally Tenant', []);
    $provider->source = reorderProvider([], [
        'again' => new WorkforceChangePage([
            new WorkforceUpsert(reorderEmployee('emp-1', $newestAt, name: 'Emp One Newest'), $newestAt),
            new WorkforceUpsert(reorderEmployee('emp-1', $middleAt, name: 'Emp One Middle'), $middleAt),
        ], $newestAt, resumeCursor: 'done', complete: true),
    ])->source;

    $report = app(WorkforceSyncRunner::class)->incremental($actor, $provider, $connectionId);

    expect($report->employees)->toBe(1)
        ->and($report->superseded)->toBe(1)
        ->and($report->conflicts)->toBe(0);
});

test('out-of-order changes in one tenant leave a second tenant untouched', function (): void {
    [$bystanderTenant] = reorderHistory('Reorder Bystander Tenant', []);
    $before = reorderFootprint($bystanderTenant);
    expect($before[0])->not->toBeEmpty()->and($before[1])->not->toBeEmpty();

    $newestAt = reorderAt('2026-09-06T08:00:00+00:00');
    $middleAt = reorderAt('2026-09-05T08:00:00+00:00');
    [$tenantId] = reorderHistory('Reorder Subject Tenant', [
        reorderStaleUpsert(),
        reorderStaleDeactivation(),
        new WorkforceUpsert(reorderEmployee('emp-2', $newestAt, name: 'Emp Two Newest'), $newestAt),
        new WorkforceUpsert(reorderEmployee('emp-2', $middleAt, name: 'Emp Two Middle'), $middleAt),
    ]);

    expect(reorderConflicts($tenantId, 'emp-1'))->toHaveCount(1)
        ->and(reorderProjection($tenantId, 'emp-2')->display_name)->toBe('Emp Two Newest')
        ->and(reorderFootprint($bystanderTenant))->toBe($before)
        ->and(reorderConflicts($bystanderTenant, 'emp-1'))->toHaveCount(0);
});
