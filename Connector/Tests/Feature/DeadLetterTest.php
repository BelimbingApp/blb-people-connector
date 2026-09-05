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
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Services\DeadLetterService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper is prefixed deadLetter and lives here.
 *
 * These cover the parking contract itself. The runner-driven half — a page
 * refused three times, parked, and the checkpoint advanced past it — arrives
 * with the implementation.
 */

const DEAD_LETTER_PROVIDER = 'test.deadletter';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function deadLetterProvider(array $bootstrapPages, array $changePages): ProviderAdapter
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
                        ?? throw new LogicException('No scripted bootstrap page.');
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    $key = $request->pageCursor ?? $request->resumeCursor ?? 'first';

                    return $this->changePages[$key] ?? throw new LogicException("No scripted change page for '{$key}'.");
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(DEAD_LETTER_PROVIDER, 'Dead Letter Test Provider', '0.1.0', '1.0.0');
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

function deadLetterHash(string $payload): string
{
    return hash('sha256', $payload);
}

test('a payload hash is accepted as a hex digest', function (): void {
    $details = new ReconciliationIssueDetails(
        reasonCode: 'projection_conflict',
        payloadHash: deadLetterHash('{"employees":[{"id":"EMP-1"}]}'),
    );

    expect($details->toArray()['payload_hash'])->toBe(deadLetterHash('{"employees":[{"id":"EMP-1"}]}'));
});

test('the payload itself cannot be smuggled into the hash field', function (): void {
    // The whole reason this field exists is to identify a page without keeping
    // it. A hash field that accepted arbitrary text would be the generic
    // payload slot docs/contracts/diagnostic-privacy.md says this DTO must not
    // have, and nobody would notice until a provider payload was sitting in an
    // operator's queue.
    expect(fn () => new ReconciliationIssueDetails(payloadHash: '{"employees":[{"id":"EMP-1","email":"ada@example.test"}]}'))
        ->toThrow(InvalidReconciliationIssueException::class);
});

test('a hash of the wrong shape is refused rather than stored', function (): void {
    foreach (['', 'deadbeef', str_repeat('z', 64), strtoupper(deadLetterHash('x')), deadLetterHash('x').'0'] as $candidate) {
        expect(fn () => new ReconciliationIssueDetails(payloadHash: $candidate))
            ->toThrow(InvalidReconciliationIssueException::class);
    }
});

test('a parked page carries a reason code and never an exception message', function (): void {
    // docs/contracts/diagnostic-privacy.md: conflict handling maps exception
    // classes to reason codes rather than persisting getMessage(). "The last
    // error" on a dead letter is therefore a code, not prose.
    expect(fn () => new ReconciliationIssueDetails(reasonCode: 'Connection refused by provider at 10.0.0.4'))
        ->toThrow(InvalidReconciliationIssueException::class);

    $details = new ReconciliationIssueDetails(reasonCode: 'projection_conflict', payloadHash: deadLetterHash('page'));

    expect($details->toArray())->toBe([
        'reason_code' => 'projection_conflict',
        'payload_hash' => deadLetterHash('page'),
    ]);
});

/**
 * A connection whose incremental feed refuses every record it carries, so each
 * pass is a refused feed and the checkpoint stays put.
 *
 * @return array{tenantId: int, connectionId: int, actor: Actor, provider: ProviderAdapter}
 */
function deadLetterRefusingFeed(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), DEAD_LETTER_PROVIDER);
    $connectionId = (int) $store->activate((int) $connection->id)->id;

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

    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');

    // A deactivation for a reference this connection has never seen is refused
    // by the identity store, so every pass over this page is a refused feed.
    $page = new WorkforceChangePage(
        [new WorkforceDeactivation(
            new ExternalReference(DEAD_LETTER_PROVIDER, WorkforceResourceType::Employee, 'GHOST-1'),
            $at,
        )],
        $at,
        resumeCursor: 'after-refusal',
        complete: true,
    );

    $provider = deadLetterProvider(
        ['first' => new WorkforcePage([], $at, resumeCursor: 'start', complete: true)],
        ['start' => $page, 'after-refusal' => $page],
    );

    $runner = app(WorkforceSyncRunner::class);
    $actor = new Actor(PrincipalType::USER, 6001, (int) $company->id, tenantId: $tenantId);
    $runner->bootstrap($actor, $provider, $connectionId);

    return ['tenantId' => $tenantId, 'connectionId' => $connectionId, 'actor' => $actor, 'provider' => $provider];
}

function deadLetterCheckpoint(int $tenantId, int $connectionId): SyncCheckpoint
{
    return SyncCheckpoint::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->where('stream', WorkforceFreshnessPolicy::stream())
        ->firstOrFail();
}

function deadLetterIssues(int $tenantId): Collection
{
    return ReconciliationIssue::query()
        ->forTenant($tenantId)
        ->where('kind', WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER)
        ->get();
}

test('a refused feed counts a failed attempt against the current cursor', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Counting Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 3);

    app(WorkforceSyncRunner::class)->incremental($f['actor'], $f['provider'], $f['connectionId']);

    expect((int) deadLetterCheckpoint($f['tenantId'], $f['connectionId'])->failed_attempts)->toBe(1)
        ->and(deadLetterIssues($f['tenantId']))->toHaveCount(0);
});

test('a page refused up to the limit is parked with its digest and the checkpoint moves past it', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Parking Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 3);
    $runner = app(WorkforceSyncRunner::class);
    $before = deadLetterCheckpoint($f['tenantId'], $f['connectionId'])->resume_cursor;

    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $report = $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);

    $parked = deadLetterIssues($f['tenantId']);
    $checkpoint = deadLetterCheckpoint($f['tenantId'], $f['connectionId']);

    expect($parked)->toHaveCount(1)
        ->and($parked->first()->details['payload_hash'] ?? null)->toMatch('/^[0-9a-f]{64}$/')
        ->and($parked->first()->details['reason_code'] ?? null)->toBe('every_record_refused')
        ->and($checkpoint->resume_cursor)->not->toBe($before)
        ->and((int) $checkpoint->failed_attempts)->toBe(0)
        ->and($report->checkpointAdvanced)->toBeTrue();
});

test('a parked page is not parked a second time when the same feed comes round again', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Idempotent Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 2);
    $runner = app(WorkforceSyncRunner::class);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);

    // The same page keeps arriving. An operator wants one queue entry for it,
    // not one per pass.
    expect(deadLetterIssues($f['tenantId']))->toHaveCount(1);
});

test('a successful pass clears the failed attempts it had accumulated', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Reset Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 5);
    $runner = app(WorkforceSyncRunner::class);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    expect((int) deadLetterCheckpoint($f['tenantId'], $f['connectionId'])->failed_attempts)->toBe(1);

    $at = new DateTimeImmutable('2026-09-02T08:00:00+00:00');
    $clean = deadLetterProvider(
        ['first' => new WorkforcePage([], $at, resumeCursor: 'start', complete: true)],
        // The refused pass did not advance, so the checkpoint is still at
        // 'start' and that is the cursor the clean pass reads from.
        ['start' => new WorkforceChangePage([], $at, resumeCursor: 'after-clean', complete: true)],
    );
    $runner->incremental($f['actor'], $clean, $f['connectionId']);

    expect((int) deadLetterCheckpoint($f['tenantId'], $f['connectionId'])->failed_attempts)->toBe(0);
});

test('an operator re-queues a parked page and the counter starts again', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Requeue Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 1);
    $runner = app(WorkforceSyncRunner::class);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $parked = deadLetterIssues($f['tenantId'])->first();

    app(DeadLetterService::class)->requeue(
        $f['connectionId'],
        (int) $parked->id,
        'dead-letter-review-2026-09-06',
    );

    expect($parked->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and((int) deadLetterCheckpoint($f['tenantId'], $f['connectionId'])->failed_attempts)->toBe(0);
});

test('re-queueing refuses an issue that is not a parked page', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Wrong Kind Tenant');
    $other = app(ReconciliationIssueStore::class)->report(
        $f['connectionId'],
        'sync:employee:OTHER',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'OTHER',
    );

    expect(fn () => app(DeadLetterService::class)->requeue($f['connectionId'], (int) $other->id, 'review-1'))
        ->toThrow(InvalidReconciliationIssueException::class);

    expect($other->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('re-queueing requires a review reference', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Unreviewed Requeue Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 1);
    app(WorkforceSyncRunner::class)->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $parked = deadLetterIssues($f['tenantId'])->first();

    expect(fn () => app(DeadLetterService::class)->requeue($f['connectionId'], (int) $parked->id, '  '))
        ->toThrow(InvalidReconciliationIssueException::class);

    expect($parked->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('a re-queued page that fails again is reopened rather than refused as new evidence', function (): void {
    $f = deadLetterRefusingFeed('Dead Letter Reopen Tenant');
    config()->set('people-connector.sync.dead_letter_attempts', 1);
    $runner = app(WorkforceSyncRunner::class);
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);
    $parked = deadLetterIssues($f['tenantId'])->first();
    app(DeadLetterService::class)->requeue($f['connectionId'], (int) $parked->id, 'dead-letter-review-2026-09-06');

    // The issue store refuses new evidence on a resolved issue. A page that was
    // parked, given another chance and failed again is precisely that case, and
    // without reopening it the next pass would throw instead of re-parking.
    $runner->incremental($f['actor'], $f['provider'], $f['connectionId']);

    expect($parked->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and(deadLetterIssues($f['tenantId']))->toHaveCount(1);
});
