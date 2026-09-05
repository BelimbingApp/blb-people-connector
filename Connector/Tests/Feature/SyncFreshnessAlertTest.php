<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\SyncFreshnessAlerter;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use Illuminate\Support\Collection;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function freshnessAlertConnection(int $tenantId): int
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::tenant(), 'test.freshness');

    return (int) $store->activate((int) $connection->id)->id;
}

function freshnessAlertCheckpoint(int $connectionId, DateTimeImmutable $asOf, int $expectedVersion): void
{
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $connectionId,
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $asOf, resumeCursor: 'cursor-'.$expectedVersion, complete: true),
        $expectedVersion,
        $asOf,
    );
}

/** @return array{0: int, 1: DateTimeImmutable, 2: DateTimeImmutable, 3: int, 4: int} */
function freshnessAlertStaleConnection(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $connectionId = freshnessAlertConnection((int) $tenant->id);
    $asOf = new DateTimeImmutable('2026-09-01T09:00:00+00:00');
    freshnessAlertCheckpoint($connectionId, $asOf, 0);

    // The default maximum age is a day; three days past the provider watermark
    // is unambiguously a breach whatever the configured maximum happens to be.
    return [$connectionId, $asOf, $asOf->modify('+3 days'), (int) $tenant->id, (int) $company->id];
}

function freshnessAlertOpenIssues(int $connectionId): int
{
    return ReconciliationIssue::query()
        ->where('connection_id', $connectionId)
        ->where('kind', SyncFreshnessAlerter::ISSUE_KIND)
        ->where('status', ReconciliationIssue::STATUS_OPEN)
        ->count();
}

test('a checkpoint older than the maximum age raises one stale issue for that breach window', function (): void {
    [$connectionId, $asOf, $now] = freshnessAlertStaleConnection('Freshness Alert Tenant');

    $issue = app(SyncFreshnessAlerter::class)->review($connectionId, $now);

    expect($issue)->not->toBeNull()
        ->and($issue->kind)->toBe(SyncFreshnessAlerter::ISSUE_KIND)
        ->and($issue->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($issue->issue_key)->toBe('sync:stale:'.$connectionId.':'.$asOf->format(DATE_ATOM))
        ->and($issue->details['reason_code'] ?? null)->toBe('exceeded_max_age');
});

test('reviewing the same breach again does not raise a second issue', function (): void {
    [$connectionId, , $now] = freshnessAlertStaleConnection('Freshness Idempotency Tenant');
    $alerter = app(SyncFreshnessAlerter::class);

    $first = $alerter->review($connectionId, $now);
    $second = $alerter->review($connectionId, $now->modify('+1 hour'));

    expect((int) $second->id)->toBe((int) $first->id)
        ->and(freshnessAlertOpenIssues($connectionId))->toBe(1);
});

test('a connection that has never synchronized is stale for its own reason', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Freshness Never Tenant']);
    $connectionId = freshnessAlertConnection((int) $tenant->id);

    $issue = app(SyncFreshnessAlerter::class)->review($connectionId, new DateTimeImmutable('2026-09-01T09:00:00+00:00'));

    expect($issue)->not->toBeNull()
        ->and($issue->issue_key)->toBe('sync:stale:'.$connectionId.':never')
        ->and($issue->details['reason_code'] ?? null)->toBe('never_synchronized');
});

test('a successful pass clears the open stale issue', function (): void {
    [$connectionId, , $now] = freshnessAlertStaleConnection('Freshness Clear Tenant');
    $alerter = app(SyncFreshnessAlerter::class);
    $raised = $alerter->review($connectionId, $now);
    freshnessAlertCheckpoint($connectionId, $now, 1);

    $cleared = $alerter->review($connectionId, $now->modify('+1 minute'));

    expect($cleared)->toBeNull()
        ->and($raised->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and(freshnessAlertOpenIssues($connectionId))->toBe(0);
});

test('a breach after a clear opens a new issue for the new window', function (): void {
    [$connectionId, , $now] = freshnessAlertStaleConnection('Freshness Rebreach Tenant');
    $alerter = app(SyncFreshnessAlerter::class);
    $first = $alerter->review($connectionId, $now);
    freshnessAlertCheckpoint($connectionId, $now, 1);
    $alerter->review($connectionId, $now->modify('+1 minute'));

    $second = $alerter->review($connectionId, $now->modify('+3 days'));

    expect($second)->not->toBeNull()
        ->and((int) $second->id)->not->toBe((int) $first->id)
        ->and($second->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and(freshnessAlertOpenIssues($connectionId))->toBe(1);
});

test('an inactive connection raises no stale issue because no pass can clear one', function (): void {
    [$connectionId, , $now] = freshnessAlertStaleConnection('Freshness Inactive Tenant');
    ProviderConnection::query()->whereKey($connectionId)->update([
        'status' => ProviderConnection::STATUS_INACTIVE,
        'active_scope_key' => null,
    ]);

    $issue = app(SyncFreshnessAlerter::class)->review($connectionId, $now);

    expect($issue)->toBeNull()
        ->and(freshnessAlertOpenIssues($connectionId))->toBe(0);
});

test('reviewing a connection outside the current tenant is refused', function (): void {
    [$connectionId, , $now, $tenantId] = freshnessAlertStaleConnection('Freshness Isolation Tenant');
    [$other] = createTenantWithCompany(['name' => 'Freshness Other Tenant']);
    app(TenantContext::class)->set((int) $other->id);

    expect(fn () => app(SyncFreshnessAlerter::class)->review($connectionId, $now))
        ->toThrow(ConnectorRecordNotFoundException::class);

    app(TenantContext::class)->set($tenantId);
    expect(freshnessAlertOpenIssues($connectionId))->toBe(0);
});

test('the reconciliation queue names a stale synchronization issue', function (): void {
    [$connectionId, , $now, , $companyId] = freshnessAlertStaleConnection('Freshness Queue Tenant');
    $user = User::factory()->create(['company_id' => $companyId]);
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
    app(SyncFreshnessAlerter::class)->review($connectionId, $now);

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertSee(__('Stale synchronization'))
        ->assertSee(__('The provider watermark is older than the maximum age.'));
});
