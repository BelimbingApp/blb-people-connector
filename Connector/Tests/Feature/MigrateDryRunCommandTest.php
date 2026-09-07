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

    expect(dryRunCall($source, $target, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['in_flight_deliveries'])->toBe(1)
        ->and($report['dead_letters'])->toBe(1)
        ->and($report['collisions'])->toBe([])
        ->and($report['blockers'])->toHaveCount(2)
        ->and($report['tables']['people_connector_connector_webhook_deliveries'])->toBe(2);
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
