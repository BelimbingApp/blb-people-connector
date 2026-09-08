<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
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
use App\Domains\PeopleConnector\Connector\Data\WorkforceFreshness;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionMaintenanceException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ConnectionMaintenanceService;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\SyncFreshnessAlerter;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/*
 * connector:connection:maintenance (#264): a bounded, audited pause on one
 * connection. Self-contained: every helper is prefixed maint and lives here;
 * the only outside helper is the platform's createTenantWithCompany().
 */

const MAINT_PROVIDER = 'test.maintenance';

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function maintAuthz(bool $allow = true): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException('connector', 'maintenance', 'The actor lacks the connector connection management capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/** A MAINT_PROVIDER adapter that records every page request it receives. */
function maintProvider(): ProviderAdapter
{
    return new class implements ProviderAdapter, ResolvesProviderPorts
    {
        public object $source;

        public function __construct()
        {
            $this->source = new class implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                public int $reads = 0;

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    $this->reads++;
                    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');

                    return new WorkforcePage([], $at, resumeCursor: 'bootstrapped', complete: true, companies: [
                        new WorkforceCompany(new ExternalReference(MAINT_PROVIDER, WorkforceResourceType::Company, 'MAINT-CO'), 'Maintenance Co', true, $at),
                    ]);
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    $this->reads++;

                    return new WorkforceChangePage([], new DateTimeImmutable('2026-09-02T08:00:00+00:00'), resumeCursor: 'after-'.$this->reads, complete: true);
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(MAINT_PROVIDER, 'Maintenance Test Provider', '0.1.0', '1.0.0');
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

/**
 * An active, bootstrapped MAINT_PROVIDER connection with a named operator.
 *
 * @return array{tenantId: int, companyId: int, connectionId: int, operator: User, actor: Actor, provider: ProviderAdapter}
 */
/** A fresh checkpoint, so the connection's workforce_freshness row (#284) is green and only the maintenance row moves. */
function maintSeedCheckpoint(array $f): void
{
    app(TenantContext::class)->set($f['tenantId']);
    $asOf = new DateTimeImmutable;
    $current = SyncCheckpoint::query()->where('connection_id', $f['connectionId'])->where('stream', WorkforceFreshnessPolicy::stream())->first();
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $f['connectionId'],
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $asOf, resumeCursor: 'cursor-fresh', complete: true),
        (int) ($current?->version ?? 0),
        $asOf,
    );
}

function maintFixture(string $name): array
{
    maintAuthz();
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $provider = maintProvider();
    if (app(ProviderRegistry::class)->find(MAINT_PROVIDER) === null) {
        app(ProviderRegistry::class)->register($provider);
    }
    $provider = app(ProviderRegistry::class)->find(MAINT_PROVIDER);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), MAINT_PROVIDER)->id);
    // A usable credential keeps the connection's provider_credential_expiry row (#296) green.
    ProviderCredentialRecord::query()->create([
        'tenant_id' => (int) $connection->tenant_id, 'connection_id' => (int) $connection->id, 'provider_id' => MAINT_PROVIDER,
        'key_id' => 'fixture-key', 'secret_reference' => 'base-integration:fixture', 'audience' => 'provider',
        'scopes' => ['workforce:read'], 'issued_at' => '2020-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00',
    ]);
    app(WorkforceSyncRunner::class)->bootstrap(app(SchedulerPrincipal::class)->forConnection($connection), $provider, (int) $connection->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    return [
        'tenantId' => (int) $tenant->id,
        'companyId' => (int) $company->id,
        'connectionId' => (int) $connection->id,
        'operator' => $operator,
        'actor' => Actor::forUser($operator),
        'provider' => $provider,
    ];
}

/** @return array{status: int, output: string} */
function maintCommand(array $f, array $options): array
{
    $status = Artisan::call('connector:connection:maintenance', ['--tenant' => $f['tenantId'], '--as' => $f['operator']->id, '--connection' => $f['connectionId']] + $options);
    // TenantScopedCommand clears the bound tenant when it ends; the test keeps using tenant services.
    app(TenantContext::class)->set($f['tenantId']);

    return ['status' => $status, 'output' => Artisan::output()];
}

/** @return array{status: int, output: string} */
function maintStart(array $f, string $until = '+2 hours', ?string $reason = 'provider-upgrade-2026-09'): array
{
    return maintCommand($f, ['--until' => now()->modify($until)->format(DATE_ATOM), '--reason' => $reason]);
}

/** @return array{maintenance_until: ?string, maintenance_reason: ?string} */
function maintWindow(int $connectionId): array
{
    return (array) ProviderConnection::query()->whereKey($connectionId)->first(['maintenance_until', 'maintenance_reason'])?->only(['maintenance_until', 'maintenance_reason']);
}

/** @return array{version: ?int, cursor: ?string, events: int} */
function maintCheckpoint(int $connectionId): array
{
    $checkpoint = SyncCheckpoint::query()->where('connection_id', $connectionId)->where('stream', WorkforceFreshnessPolicy::stream())->first();

    return [
        'version' => $checkpoint === null ? null : (int) $checkpoint->version,
        'cursor' => $checkpoint?->resume_cursor,
        // Events hang off the checkpoint, not the connection: SQLite tolerated
        // the wrong column at first, PostgreSQL did not.
        'events' => SyncCheckpointEvent::query()->whereIn('checkpoint_id', SyncCheckpoint::query()->where('connection_id', $connectionId)->pluck('id'))->count(),
    ];
}

function maintDelivery(array $f, string $id = 'delivery-maint'): WebhookDelivery
{
    return WebhookDelivery::query()->create([
        'tenant_id' => $f['tenantId'], 'connection_id' => $f['connectionId'], 'delivery_id' => $id,
        'status' => WebhookDelivery::STATUS_ACCEPTED, 'received_at' => now(),
    ]);
}

function maintRunJob(array $f, WebhookDelivery $delivery): void
{
    app(TenantContext::class)->clear();
    app()->call([new RunIncrementalWorkforceSync($f['tenantId'], $f['connectionId'], (int) $delivery->id), 'handle']);
    app(TenantContext::class)->set($f['tenantId']);
}

test('a sync pass over a connection in maintenance reads zero pages and moves no checkpoint', function (): void {
    $f = maintFixture('Maintenance Pass Tenant');
    expect(maintStart($f)['status'])->toBe(0);
    $before = maintCheckpoint($f['connectionId']);
    $readsBefore = $f['provider']->source->reads;

    expect(fn () => app(WorkforceSyncRunner::class)->incremental(
        app(SchedulerPrincipal::class)->forConnection(ProviderConnection::query()->findOrFail($f['connectionId'])),
        $f['provider'],
        $f['connectionId'],
    ))->toThrow(ConnectionMaintenanceException::class);

    expect($f['provider']->source->reads)->toBe($readsBefore)
        ->and(maintCheckpoint($f['connectionId']))->toBe($before)
        ->and(OperatorAudit::query()->where('connection_id', $f['connectionId'])->where('operation', OperatorAuditOperation::SyncPass->value)->count())->toBe(1);
});

test('people-connector:sync names the window and skips the connection without moving the checkpoint', function (): void {
    $f = maintFixture('Maintenance Sync Command Tenant');
    $until = now()->modify('+3 hours')->format(DATE_ATOM);
    expect(maintCommand($f, ['--until' => $until])['status'])->toBe(0);
    $before = maintCheckpoint($f['connectionId']);

    expect(Artisan::call('people-connector:sync', ['connection' => $f['connectionId']]))->toBe(0)
        ->and(Artisan::output())->toContain("Connection {$f['connectionId']}: in maintenance until ".(new DateTimeImmutable($until))->format(DATE_ATOM))
        ->and(maintCheckpoint($f['connectionId']))->toBe($before);
});

test('a webhook delivery in maintenance is deferred without an attempt and replays after --end', function (): void {
    $f = maintFixture('Maintenance Webhook Tenant');
    expect(maintStart($f)['status'])->toBe(0);
    $delivery = maintDelivery($f);

    maintRunJob($f, $delivery);

    $delivery = $delivery->fresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DEFERRED)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->failure_reason)->toBeNull();

    // Still inside the window a replay would only be deferred again, so it is refused.
    expect(Artisan::call('connector:webhook:replay', ['delivery' => $delivery->id, '--tenant' => $f['tenantId'], '--as' => $f['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('in maintenance');

    expect(maintCommand($f, ['--end' => true])['status'])->toBe(0)
        ->and(maintWindow($f['connectionId']))->toBe(['maintenance_until' => null, 'maintenance_reason' => null]);

    // The sync queue driver runs the re-dispatched pass inline.
    $checkpointBefore = maintCheckpoint($f['connectionId']);
    expect(Artisan::call('connector:webhook:replay', ['delivery' => $delivery->id, '--tenant' => $f['tenantId'], '--as' => $f['operator']->id]))->toBe(0);
    $replay = WebhookDelivery::query()->where('replayed_from_id', $delivery->id)->firstOrFail();
    expect($replay->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($replay->attempts)->toBe(1)
        ->and(maintCheckpoint($f['connectionId'])['version'])->toBe($checkpointBefore['version'] + 1);
});

test('a lapsed window is not maintenance: the deferred delivery replays and the row is cleared on read', function (): void {
    $f = maintFixture('Maintenance Lapse Tenant');
    expect(maintStart($f, '+1 hour')['status'])->toBe(0);
    $delivery = maintDelivery($f);
    maintRunJob($f, $delivery);
    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_DEFERRED);

    Carbon::setTestNow(now()->addHours(2));

    expect(Artisan::call('connector:webhook:replay', ['delivery' => $delivery->id, '--tenant' => $f['tenantId'], '--as' => $f['operator']->id]))->toBe(0)
        ->and(WebhookDelivery::query()->where('replayed_from_id', $delivery->id)->value('status'))->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and(maintWindow($f['connectionId']))->toBe(['maintenance_until' => null, 'maintenance_reason' => null]);
});

test('a window in the past or more than seven days away is refused with no write', function (): void {
    $f = maintFixture('Maintenance Bounds Tenant');
    $before = maintWindow($f['connectionId']);

    $past = maintStart($f, '-1 minute');
    $far = maintStart($f, '+8 days');

    expect($past['status'])->toBe(1)
        ->and($past['output'])->toContain('in the future')
        ->and($far['status'])->toBe(1)
        ->and($far['output'])->toContain('at most 7 days')
        ->and(maintWindow($f['connectionId']))->toBe($before)
        ->and(OperatorAudit::query()->where('operation', OperatorAuditOperation::ConnectionMaintenance->value)->count())->toBe(0);
});

test('a retired connection is refused', function (): void {
    $f = maintFixture('Maintenance Retired Tenant');
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-07');

    $result = maintStart($f);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('retired')
        ->and(maintWindow($f['connectionId']))->toBe(['maintenance_until' => null, 'maintenance_reason' => null]);
});

test('an operator without the manage capability is refused before any write', function (): void {
    $f = maintFixture('Maintenance Denied Tenant');
    maintAuthz(false);
    $before = ProviderConnection::query()->findOrFail($f['connectionId'])->getAttributes();

    $result = maintStart($f);

    expect($result['status'])->toBe(1)
        ->and(ProviderConnection::query()->findOrFail($f['connectionId'])->getAttributes())->toBe($before)
        ->and(OperatorAudit::query()->where('operation', OperatorAuditOperation::ConnectionMaintenance->value)->count())->toBe(0);
});

test('an operator in tenant A cannot put tenant B connection into maintenance', function (): void {
    $a = maintFixture('Maintenance Tenant A');
    $b = maintFixture('Maintenance Tenant B');

    // Naming B's tenant with A's operator, and naming B's connection inside A's tenant.
    $foreignTenant = Artisan::call('connector:connection:maintenance', ['--tenant' => $b['tenantId'], '--as' => $a['operator']->id, '--connection' => $b['connectionId'], '--until' => now()->addHour()->format(DATE_ATOM)]);
    $foreignConnection = Artisan::call('connector:connection:maintenance', ['--tenant' => $a['tenantId'], '--as' => $a['operator']->id, '--connection' => $b['connectionId'], '--until' => now()->addHour()->format(DATE_ATOM)]);

    expect($foreignTenant)->toBe(1)
        ->and($foreignConnection)->toBe(1)
        ->and(maintWindow($b['connectionId']))->toBe(['maintenance_until' => null, 'maintenance_reason' => null]);
});

test('connector doctor shows connection_maintenance yellow for the tenant in maintenance only, without changing the exit code', function (): void {
    config()->set('queue.default', 'database');
    $inMaintenance = maintFixture('Maintenance Doctor Tenant');
    $other = maintFixture('Maintenance Doctor Other Tenant');
    // Seed before the window opens: a checkpoint cannot be written through a
    // paused connection, and the freshness row (#284) must stay green here.
    maintSeedCheckpoint($inMaintenance);
    maintSeedCheckpoint($other);
    app(TenantContext::class)->set($inMaintenance['tenantId']);
    $until = now()->addHours(4)->format(DATE_ATOM);
    expect(maintCommand($inMaintenance, ['--until' => $until])['status'])->toBe(0);

    $rows = [];
    foreach ([$inMaintenance, $other] as $f) {
        expect(Artisan::call('connector:doctor', ['--tenant' => $f['tenantId'], '--as' => $f['operator']->id, '--json' => true]))->toBe(0);
        $rows[] = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks'])->firstWhere('check', 'connection_maintenance');
    }

    expect($rows[0]['status'])->toBe('yellow')
        ->and($rows[0]['count'])->toBe(1)
        ->and($rows[0]['detail'])->toContain((new DateTimeImmutable($until))->format(DATE_ATOM))
        ->and($rows[1])->toBe(['check' => 'connection_maintenance', 'status' => 'green', 'count' => 0, 'detail' => '0 in maintenance']);
});

test('one audit row per start and per end carries the window and reason and no credential material', function (): void {
    $f = maintFixture('Maintenance Audit Tenant');
    $until = now()->addHours(5)->format(DATE_ATOM);
    expect(maintCommand($f, ['--until' => $until, '--reason' => 'provider-upgrade'])['status'])->toBe(0)
        ->and(maintCommand($f, ['--end' => true])['status'])->toBe(0);

    $rows = OperatorAudit::query()->where('operation', OperatorAuditOperation::ConnectionMaintenance->value)->orderBy('id')->get();
    $expectedUntil = (new DateTimeImmutable($until))->format(DATE_ATOM);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->connection_id)->toBe($f['connectionId'])
        ->and($rows[0]->actor_id)->toBe((int) $f['operator']->id)
        ->and($rows[0]->before_summary)->toBe(['maintenance_until' => null, 'maintenance_reason' => null])
        ->and($rows[0]->after_summary)->toBe(['maintenance_until' => $expectedUntil, 'maintenance_reason' => 'provider-upgrade'])
        ->and($rows[1]->before_summary)->toBe(['maintenance_until' => $expectedUntil, 'maintenance_reason' => 'provider-upgrade'])
        ->and($rows[1]->after_summary)->toBe(['maintenance_until' => null, 'maintenance_reason' => null]);
    foreach ($rows as $row) {
        expect(json_encode([$row->before_summary, $row->after_summary]))->not->toContain('secret')
            ->and(json_encode([$row->before_summary, $row->after_summary]))->not->toContain('token');
    }
});

test('a freshness breach inside the window is stale (maintenance) and raises no new reconciliation issue', function (): void {
    $f = maintFixture('Maintenance Freshness Tenant');
    expect(maintStart($f, '+6 days')['status'])->toBe(0);
    // The checkpoint watermark is 2026-09-02; three days later is past the default maximum age.
    $later = new DateTimeImmutable('2026-09-05T08:00:00+00:00');

    $freshness = app(WorkforceFreshnessPolicy::class)->for($f['connectionId'], $later);
    $issue = app(SyncFreshnessAlerter::class)->review($f['connectionId'], $later);

    expect($freshness->isStale())->toBeTrue()
        ->and($freshness->staleReason)->toBe(WorkforceFreshness::REASON_MAINTENANCE)
        ->and($issue)->toBeNull()
        ->and(ReconciliationIssue::query()->where('connection_id', $f['connectionId'])->where('kind', SyncFreshnessAlerter::ISSUE_KIND)->count())->toBe(0);
});

test('a reason at the bound is stored and audited; one past it is refused before the write', function (): void {
    $f = maintFixture('Maintenance Reason Bound Tenant');
    $audits = OperatorAudit::query()->count();

    // The reason is copied into the operator audit summary, whose own bound is
    // 190 bytes: a longer reason would write the window and then lose its
    // audit row to OperatorAuditException.
    $service = app(ConnectionMaintenanceService::class);
    $service->start($f['actor'], $f['connectionId'], now()->addDay()->toImmutable(), str_repeat('r', 190));
    expect(OperatorAudit::query()->count())->toBe($audits + 1);

    expect(fn () => $service->start($f['actor'], $f['connectionId'], now()->addDay()->toImmutable(), str_repeat('r', 191)))
        ->toThrow(ConnectionMaintenanceException::class);
    expect(OperatorAudit::query()->count())->toBe($audits + 1);
});
