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
use App\Domains\PeopleConnector\Connector\Data\WorkforceMerge;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceSyncReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper is prefixed replay and lives here, so the file
 * passes or fails alone for its own reasons. The only outside helper is the
 * platform's createTenantWithCompany().
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const REPLAY_PROVIDER = 'test.replay';

function replayRef(WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference(REPLAY_PROVIDER, $type, $id);
}

function replayAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

function replayEmployee(string $id, DateTimeImmutable $at, bool $active = true, ?string $name = null): WorkforceEmployee
{
    return new WorkforceEmployee(
        replayRef(WorkforceResourceType::Employee, $id),
        replayRef(WorkforceResourceType::Company, 'co-1'),
        $name ?? ucfirst($id),
        $active,
        $at,
        $at,
        employeeNumber: strtoupper($id),
        email: strtolower($id).'@example.test',
    );
}

/** @return array{int, int, Actor} */
function replayTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), REPLAY_PROVIDER);
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
        new Actor(PrincipalType::USER, 7001, (int) $company->id, tenantId: (int) $tenant->id),
    ];
}

function replayProvider(array $bootstrapPages, array $changePages): ProviderAdapter
{
    return new class($bootstrapPages, $changePages) implements ProviderAdapter, ResolvesProviderPorts
    {
        public object $source;

        public function __construct(array $bootstrapPages, array $changePages)
        {
            $this->source = new class($bootstrapPages, $changePages) implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                /** @var list<array{string, ?string, ?string}> */
                public array $calls = [];

                public function __construct(private array $bootstrapPages, private array $changePages) {}

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    $this->calls[] = ['bootstrap', null, $request->pageCursor];

                    return $this->bootstrapPages[$request->pageCursor ?? 'first']
                        ?? throw new LogicException("No scripted bootstrap page for '{$request->pageCursor}'.");
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    $this->calls[] = ['changes', $request->resumeCursor, $request->pageCursor];
                    $key = $request->pageCursor ?? $request->resumeCursor ?? 'first';

                    return $this->changePages[$key]
                        ?? throw new LogicException("No scripted change page for '{$key}'.");
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(REPLAY_PROVIDER, 'Replay Test Provider', '0.1.0', '1.0.0');
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
            return new ProviderHealth(ProviderHealthState::Healthy, replayAt('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return $this->source instanceof $contract ? $this->source : null;
        }
    };
}

function replayIdentityState(int $tenantId, string $externalId): ?string
{
    return ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('external_id', $externalId)
        ->value('state');
}

function replayEmployeeEntityId(int $tenantId, string $externalId): ?int
{
    $id = ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('external_id', $externalId)
        ->value('workforce_entity_id');

    return $id === null ? null : (int) $id;
}

function replayCheckpoint(int $tenantId, int $connectionId): SyncCheckpoint
{
    return SyncCheckpoint::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->where('stream', WorkforceFreshnessPolicy::stream())
        ->firstOrFail();
}

/**
 * Bootstrap one employee, then run one incremental pass that deactivates and
 * immediately rehires them. The checkpoint is then at version 2, and version 1
 * is the cursor a replay rewinds to.
 *
 * @return array{int, int, Actor, ProviderAdapter}
 */
function replayHistory(string $name): array
{
    [$tenantId, $connectionId, $actor] = replayTenant($name);
    $bootstrapAt = replayAt('2026-09-01T08:00:00+00:00');
    $deactivatedAt = replayAt('2026-09-02T08:00:00+00:00');
    $rehiredAt = replayAt('2026-09-03T08:00:00+00:00');

    $provider = replayProvider(
        [
            'first' => new WorkforcePage(
                [replayEmployee('emp-1', $bootstrapAt)],
                $bootstrapAt,
                resumeCursor: 'after-bootstrap',
                complete: true,
                companies: [new WorkforceCompany(replayRef(WorkforceResourceType::Company, 'co-1'), 'Replay Co', true, $bootstrapAt)],
            ),
        ],
        [
            // Read from the bootstrap cursor: switch the employee off, then back
            // on. Both facts are in one feed and the later one wins.
            'after-bootstrap' => new WorkforceChangePage(
                [
                    new WorkforceDeactivation(replayRef(WorkforceResourceType::Employee, 'emp-1'), $deactivatedAt),
                    new WorkforceUpsert(replayEmployee('emp-1', $rehiredAt, name: 'Emp One Rehired'), $rehiredAt),
                ],
                $rehiredAt,
                resumeCursor: 'after-rehire',
                complete: true,
            ),
            // The replay rewinds to the bootstrap cursor and reads the same page
            // again, so 'after-bootstrap' serves both passes.
            'after-rehire' => new WorkforceChangePage([], $rehiredAt, resumeCursor: 'after-rehire', complete: true),
        ],
    );

    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, $provider, $connectionId);
    $runner->incremental($actor, $provider, $connectionId);

    return [$tenantId, $connectionId, $actor, $provider];
}

test('a replay re-reads the change feed from the older checkpoint cursor', function (): void {
    [, $connectionId, $actor, $provider] = replayHistory('Replay Cursor Tenant');
    $before = count($provider->source->calls);

    app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect(array_slice($provider->source->calls, $before))
        ->toBe([['changes', 'after-bootstrap', null]]);
});

test('a replay leaves the current checkpoint exactly where it was', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = replayHistory('Replay Checkpoint Tenant');
    $before = replayCheckpoint($tenantId, $connectionId);
    $version = (int) $before->version;
    $cursor = $before->resume_cursor;

    $report = app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);
    $after = replayCheckpoint($tenantId, $connectionId);

    expect((int) $after->version)->toBe($version)
        ->and($after->resume_cursor)->toBe($cursor)
        ->and($report->pass)->toBe('replay')
        ->and($report->checkpointAdvanced)->toBeFalse();
});

test('replaying the same changes creates no duplicate projections and keeps the stable entity id', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = replayHistory('Replay Idempotency Tenant');
    $entityId = replayEmployeeEntityId($tenantId, 'emp-1');
    $identities = ExternalIdentity::query()->forTenant($tenantId)->count();
    $projections = WorkforceEmployeeProjection::query()->forTenant($tenantId)
        ->withoutCompanyScope('counting every projection in the tenant is the assertion')->count();

    app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect(replayEmployeeEntityId($tenantId, 'emp-1'))->toBe($entityId)
        ->and(ExternalIdentity::query()->forTenant($tenantId)->count())->toBe($identities)
        ->and(WorkforceEmployeeProjection::query()->forTenant($tenantId)
            ->withoutCompanyScope('counting every projection in the tenant is the assertion')->count())->toBe($projections);
});

test('a deactivation replayed after the rehire that superseded it does not switch the identity off', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = replayHistory('Replay Ordering Tenant');
    expect(replayIdentityState($tenantId, 'emp-1'))->toBe(ExternalIdentity::STATE_ACTIVE);

    app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect(replayIdentityState($tenantId, 'emp-1'))->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('a change the replay has already superseded raises no reconciliation issue', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = replayHistory('Replay Quiet Tenant');
    $before = ReconciliationIssue::query()->forTenant($tenantId)->count();

    $report = app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect(ReconciliationIssue::query()->forTenant($tenantId)->count())->toBe($before)
        ->and($report->conflicts)->toBe(0)
        ->and($report->superseded)->toBe(1);
});

test('a record missing from a replayed page is never deactivated', function (): void {
    [$tenantId, $connectionId, $actor, $provider] = replayHistory('Replay Absence Tenant');

    // The replayed page carries emp-1 only. An employee the provider simply did
    // not mention is not a statement that they left.
    app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect(replayIdentityState($tenantId, 'emp-1'))->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('replaying a checkpoint version the connection never reached is refused', function (): void {
    [, $connectionId, $actor, $provider] = replayHistory('Replay Unknown Version Tenant');

    expect(fn () => app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 99))
        ->toThrow(WorkforceSyncException::class);
});

test('replaying a connection that has never checkpointed is refused', function (): void {
    [, $connectionId, $actor] = replayTenant('Replay No Checkpoint Tenant');
    $provider = replayProvider([], []);

    expect(fn () => app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1))
        ->toThrow(WorkforceSyncException::class);
});

test('a merge in a replayed page re-queues the same review rather than a second one', function (): void {
    [$tenantId, $connectionId, $actor] = replayTenant('Replay Merge Tenant');
    $bootstrapAt = replayAt('2026-09-01T08:00:00+00:00');
    $mergedAt = replayAt('2026-09-02T08:00:00+00:00');
    $merge = new WorkforceMerge(
        replayRef(WorkforceResourceType::Employee, 'emp-1'),
        replayRef(WorkforceResourceType::Employee, 'emp-2'),
        $mergedAt,
    );
    $provider = replayProvider(
        [
            'first' => new WorkforcePage(
                [replayEmployee('emp-1', $bootstrapAt), replayEmployee('emp-2', $bootstrapAt)],
                $bootstrapAt,
                resumeCursor: 'after-bootstrap',
                complete: true,
                companies: [new WorkforceCompany(replayRef(WorkforceResourceType::Company, 'co-1'), 'Replay Co', true, $bootstrapAt)],
            ),
        ],
        [
            'after-bootstrap' => new WorkforceChangePage([$merge], $mergedAt, resumeCursor: 'after-merge', complete: true),
            'after-merge' => new WorkforceChangePage([], $mergedAt, resumeCursor: 'after-merge', complete: true),
        ],
    );
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, $provider, $connectionId);
    $runner->incremental($actor, $provider, $connectionId);
    $before = ReconciliationIssue::query()->forTenant($tenantId)->count();

    $report = $runner->replay($actor, $provider, $connectionId, fromVersion: 1);

    // A merge is a request for a human decision, keyed by the pair. Replaying
    // it must land on the same queue entry, not open a second one.
    expect(ReconciliationIssue::query()->forTenant($tenantId)->count())->toBe($before)
        ->and($report->mergesQueued)->toBe(1)
        ->and($report->superseded)->toBe(0);
});

test('a merge older than the last observed fact is still queued rather than skipped as superseded', function (): void {
    [$tenantId, $connectionId, $actor] = replayTenant('Replay Old Merge Tenant');
    $mergedAt = replayAt('2026-09-01T08:00:00+00:00');
    $observedAt = replayAt('2026-09-05T08:00:00+00:00');
    $merge = new WorkforceMerge(
        replayRef(WorkforceResourceType::Employee, 'emp-1'),
        replayRef(WorkforceResourceType::Employee, 'emp-2'),
        $mergedAt,
    );
    $provider = replayProvider(
        [
            'first' => new WorkforcePage(
                [replayEmployee('emp-1', $observedAt), replayEmployee('emp-2', $observedAt)],
                $observedAt,
                resumeCursor: 'after-bootstrap',
                complete: true,
                companies: [new WorkforceCompany(replayRef(WorkforceResourceType::Company, 'co-1'), 'Replay Co', true, $observedAt)],
            ),
        ],
        [
            'after-bootstrap' => new WorkforceChangePage([$merge], $observedAt, resumeCursor: 'after-merge', complete: true),
            'after-merge' => new WorkforceChangePage([], $observedAt, resumeCursor: 'after-merge', complete: true),
        ],
    );
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, $provider, $connectionId);
    ReconciliationIssue::query()->forTenant($tenantId)->delete();

    // The merge predates every fact currently on file. For an upsert or a
    // deactivation that means superseded; for a merge it does not, because the
    // decision it asks a human for has not been made yet.
    $report = $runner->replay($actor, $provider, $connectionId, fromVersion: 1);

    expect($report->mergesQueued)->toBe(1)
        ->and($report->superseded)->toBe(0)
        ->and(ReconciliationIssue::query()->forTenant($tenantId)->count())->toBe(1);
});

test('a clean replay does not report its feed as refused', function (): void {
    [, $connectionId, $actor, $provider] = replayHistory('Replay Refusal Honesty Tenant');

    $report = app(WorkforceSyncRunner::class)->replay($actor, $provider, $connectionId, fromVersion: 1);

    // A replay never advances the checkpoint on purpose. That must not be read
    // as "the provider refused everything", which is a different fact with a
    // different remedy: refusal means conflicts and nothing effected.
    expect($report->feedRefused())->toBeFalse()
        ->and($report->conflicts)->toBe(0);
});

test('a replay that only skipped superseded facts is not reported as empty', function (): void {
    // Built directly rather than through a fixture: the point is a pass whose
    // every change was superseded, and a runner fixture that happens to apply
    // one record would pass this for the wrong reason.
    $report = new WorkforceSyncReport(
        connectionId: 1,
        stream: 'workforce',
        pass: 'replay',
        pages: 1,
        companies: 0,
        organizationUnits: 0,
        positions: 0,
        employees: 0,
        deactivations: 0,
        reactivations: 0,
        mergesQueued: 0,
        conflicts: 0,
        checkpointVersion: 2,
        asOf: replayAt('2026-09-06T08:00:00+00:00'),
        checkpointAdvanced: false,
        superseded: 1,
    );

    // The adapter did send this record. Skipping it because current state is
    // already ahead is not the same as the feed carrying nothing, and an
    // operator reading "empty" would go looking for a broken provider.
    expect($report->seen())->toBe(1)
        ->and($report->empty())->toBeFalse()
        ->and($report->feedRefused())->toBeFalse();
});
