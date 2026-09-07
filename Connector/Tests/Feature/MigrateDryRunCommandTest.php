<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * connector:migrate:dry-run (#240): counts, collisions, in-flight deliveries
 * and dead letters of a source tenant against a target, exit non-zero on a
 * blocker, nothing written. Self-contained: helpers are prefixed dryRun.
 */
afterEach(fn () => app(TenantContext::class)->clear());

/** Allows the move capability only while the current tenant is one of $tenantIds. */
function dryRunAuthz(array $tenantIds): void
{
    app()->instance(AuthorizationService::class, new class($tenantIds) implements AuthorizationService
    {
        public function __construct(private array $tenantIds) {}

        private function allowed(): bool
        {
            return in_array(app(TenantContext::class)->currentTenantId(), $this->tenantIds, true);
        }

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allowed() ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allowed()) {
                throw new ProviderAuthorizationException(providerId: 'connector', operation: 'test', message: 'The actor lacks the capability in tenant '.app(TenantContext::class)->currentTenantId().'.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

/** Allows everything and records each call with the tenant it was asked under. */
function dryRunAuthzSpy(): object
{
    $spy = new class implements AuthorizationService
    {
        /** @var list<array{string, int|null}> */
        public array $calls = [];

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            $this->calls[] = ['can', app(TenantContext::class)->currentTenantId()];

            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->calls[] = ['authorize', app(TenantContext::class)->currentTenantId()];
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    };
    app()->instance(AuthorizationService::class, $spy);

    return $spy;
}

/** @return array{tenantId: int, operator: User, connection: int} */
function dryRunTenant(string $name, string $provider): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $provider)->id);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => (int) $connection->id];
}

function dryRunIdentity(array $t, string $provider, string $externalId): int
{
    app(TenantContext::class)->set($t['tenantId']);

    return (int) app(WorkforceIdentityStore::class)->resolveOrCreateIdentity(
        $t['connection'], new ExternalReference($provider, WorkforceResourceType::Employee, $externalId), now(),
    )->id;
}

/** @return array<string, int> */
function dryRunCounts(): array
{
    $counts = [];
    foreach (array_keys(config('people-connector.retention')) as $table) {
        $counts[$table] = (int) DB::table($table)->count();
    }
    ksort($counts);

    return $counts;
}

function dryRunCall(array $source, array $target, array $extra = []): int
{
    app(TenantContext::class)->clear();

    return Artisan::call('connector:migrate:dry-run', ['source' => $source['tenantId'], '--to' => $target['tenantId'], '--as' => $source['operator']->id, ...$extra]);
}

test('a source identity the target already maps is listed as a collision and the run exits 1, writing nothing', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    dryRunAuthz([$source['tenantId'], $target['tenantId']]);
    $collidingSource = dryRunIdentity($source, 'test.move', 'EMP-100');
    dryRunIdentity($source, 'test.move', 'EMP-200');
    $collidingTarget = dryRunIdentity($target, 'test.move', 'EMP-100');
    $before = dryRunCounts();

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['blocked'])->toBeTrue()
        ->and($report['collisions'])->toBe([['source_identity' => $collidingSource, 'target_identity' => $collidingTarget, 'resource_type' => 'employee', 'external_id_hash' => substr(hash('sha256', 'EMP-100'), 0, 12)]])
        ->and($report['blockers'])->toHaveCount(1)
        ->and($report['tables']['people_connector_connector_external_identities'])->toBe(2)
        ->and($report['tables']['people_connector_connector_workforce_entities'])->toBe(2)
        ->and($report['tables']['people_connector_connector_provider_connections'])->toBe(1)
        ->and(array_keys($report['tables']))->toContain('people_connector_connector_operator_audits')
        ->and($report['in_flight_deliveries'])->toBe(0)
        ->and($report['dead_letters'])->toBe(0)
        ->and(json_encode($report))->not->toContain('EMP-100');

    expect(dryRunCall($source, $target))->toBe(1)
        ->and(Artisan::output())->toContain('Nothing was written', "{$collidingSource}", 'employee', 'Blocked: 1 identity collision');
    expect(dryRunCounts())->toBe($before);
});

test('with no collision, nothing in flight and no dead letter the run exits 0', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    dryRunAuthz([$source['tenantId'], $target['tenantId']]);
    dryRunIdentity($source, 'test.move', 'EMP-100');
    dryRunIdentity($target, 'test.move', 'EMP-900');

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['collisions'])->toBe([])->and($report['blocked'])->toBeFalse();
});

test('a delivery still in flight and an open parked page are blockers; a third tenant\'s are not', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    $third = dryRunTenant('Move Bystander', 'test.move');
    dryRunAuthz([$source['tenantId'], $target['tenantId']]);
    foreach ([[$source, 'accepted'], [$source, 'delivered'], [$third, 'accepted']] as [$t, $status]) {
        WebhookDelivery::query()->create(['tenant_id' => $t['tenantId'], 'connection_id' => $t['connection'], 'delivery_id' => 'd-'.$status.'-'.$t['tenantId'], 'status' => $status, 'received_at' => now()]);
    }
    foreach ([$source, $third] as $t) {
        app(TenantContext::class)->set($t['tenantId']);
        app(ReconciliationIssueStore::class)->report($t['connection'], 'sync:page:'.$t['tenantId'], WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER, new ReconciliationIssueDetails(reasonCode: 'page_refused'), WorkforceResourceType::Employee->value, 'PAGE-1');
    }
    // Neither a resolved dead letter nor an open conflict of another kind is a blocker.
    app(TenantContext::class)->set($source['tenantId']);
    $resolved = app(ReconciliationIssueStore::class)->report($source['connection'], 'sync:page:resolved', WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER, new ReconciliationIssueDetails(reasonCode: 'page_refused'), WorkforceResourceType::Employee->value, 'PAGE-2');
    ReconciliationIssue::query()->forTenant($source['tenantId'])->whereKey($resolved->id)->update(['status' => ReconciliationIssue::STATUS_RESOLVED]);
    app(ReconciliationIssueStore::class)->report($source['connection'], 'sync:employee:CONFLICT', 'sync_conflict', new ReconciliationIssueDetails(reasonCode: 'review_required'), WorkforceResourceType::Employee->value, 'CONFLICT-1');

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['in_flight_deliveries'])->toBe(1)
        ->and($report['dead_letters'])->toBe(1)
        ->and($report['collisions'])->toBe([])
        ->and($report['blockers'])->toHaveCount(2)
        ->and($report['tables']['people_connector_connector_webhook_deliveries'])->toBe(2)
        ->and($report['tables']['people_connector_connector_reconciliation_issues'])->toBe(3);
});

test('an operator not admitted by the target tenant is refused before anything is read', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    dryRunAuthz([$source['tenantId']]);

    expect(dryRunCall($source, $target))->toBe(1)
        ->and(Artisan::output())->toContain('lacks the capability in tenant '.$target['tenantId'])
        ->and(Artisan::output())->not->toContain('Nothing was written');

    dryRunAuthz([$target['tenantId']]);
    expect(dryRunCall($source, $target))->toBe(1)
        ->and(Artisan::output())->toContain('lacks the capability in tenant '.$source['tenantId']);

    expect(Artisan::call('connector:migrate:dry-run', ['source' => $source['tenantId'], '--to' => $source['tenantId'], '--as' => $source['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('must differ');
    expect(Artisan::call('connector:migrate:dry-run', ['source' => $source['tenantId'], '--as' => $source['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('pass --to=<tenant id>');
});

test('a delivery whose retry is still pending is in flight; a dead-lettered one is not', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    dryRunAuthz([$source['tenantId'], $target['tenantId']]);

    // RunIncrementalWorkforceSync::handle marks the delivery `failed` and
    // rethrows while attempts remain, so the job is back on the queue for
    // another attempt. That is unfinished work a tenant move would strand.
    WebhookDelivery::query()->create(['tenant_id' => $source['tenantId'], 'connection_id' => $source['connection'], 'delivery_id' => 'd-failed-retrying', 'status' => WebhookDelivery::STATUS_FAILED, 'attempts' => 1, 'received_at' => now()]);
    WebhookDelivery::query()->create(['tenant_id' => $source['tenantId'], 'connection_id' => $source['connection'], 'delivery_id' => 'd-dead', 'status' => WebhookDelivery::STATUS_DEAD_LETTERED, 'attempts' => 3, 'received_at' => now()]);

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['in_flight_deliveries'])->toBe(1)
        ->and($report['blockers'])->toHaveCount(1)
        ->and($report['dead_letters'])->toBe(0);
});

test('a non-active identity on either side is not a collision', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    dryRunAuthz([$source['tenantId'], $target['tenantId']]);
    // Source retired identity vs target active: not reported.
    $retiredSource = dryRunIdentity($source, 'test.move', 'EMP-RETIRED-SOURCE');
    dryRunIdentity($target, 'test.move', 'EMP-RETIRED-SOURCE');
    ExternalIdentity::query()->forTenant($source['tenantId'])->whereKey($retiredSource)->update(['state' => 'replaced']);
    // Source active vs target retired identity: not reported either.
    dryRunIdentity($source, 'test.move', 'EMP-RETIRED-TARGET');
    $retiredTarget = dryRunIdentity($target, 'test.move', 'EMP-RETIRED-TARGET');
    ExternalIdentity::query()->forTenant($target['tenantId'])->whereKey($retiredTarget)->update(['state' => 'replaced']);

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['collisions'])->toBe([])->and($report['blocked'])->toBeFalse();
});

test('an operator with no company is an invalid actor: refused before either tenant is asked, nothing written', function (): void {
    $source = dryRunTenant('Move Source', 'test.move');
    $target = dryRunTenant('Move Target', 'test.move');
    $sibling = dryRunTenant('Move Sibling', 'test.move');
    dryRunIdentity($source, 'test.move', 'EMP-100');
    dryRunIdentity($sibling, 'test.move', 'EMP-100');
    // The factory's default user has no company, so Actor::forUser() builds
    // the actor validate() refuses: the shape the command meets in production.
    $operator = User::factory()->create();
    expect(Actor::forUser($operator)->validate())->not->toBeNull();
    $spy = dryRunAuthzSpy();
    $before = dryRunCounts();
    $siblingBefore = ExternalIdentity::query()->forTenant($sibling['tenantId'])->pluck('external_id_hash', 'id')->all();

    app(TenantContext::class)->clear();
    expect(Artisan::call('connector:migrate:dry-run', ['source' => $source['tenantId'], '--to' => $target['tenantId'], '--as' => $operator->id]))->toBe(1)
        ->and(Artisan::output())->toContain('valid operator')
        ->and(Artisan::output())->not->toContain('Nothing was written')
        ->and($spy->calls)->toBe([]);
    expect(dryRunCounts())->toBe($before)
        ->and(ExternalIdentity::query()->forTenant($sibling['tenantId'])->pluck('external_id_hash', 'id')->all())->toBe($siblingBefore);
});
