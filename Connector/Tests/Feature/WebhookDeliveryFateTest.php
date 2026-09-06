<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;

/**
 * The sync job reports a recorded delivery's fate back to its ledger row
 * (#223), which is what makes "only a failed delivery can be replayed" a
 * decision the replay command can take.
 */
beforeEach(function (): void {
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
});

afterEach(fn () => app(TenantContext::class)->clear());

/** @return array{tenantId: int, connection: ProviderConnection, delivery: WebhookDelivery} */
function fateFixture(string $provider = FirstPartyPeopleAdapter::ID): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Fate Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $provider)->id);
    // An incremental pass resumes from a bootstrap checkpoint, as in production.
    $adapter = app(ProviderRegistry::class)->find($provider);
    app(WorkforceSyncRunner::class)->bootstrap(app(SchedulerPrincipal::class)->forConnection($connection), $adapter, (int) $connection->id);
    $delivery = WebhookDelivery::query()->create([
        'tenant_id' => (int) $tenant->id, 'connection_id' => $connection->id, 'delivery_id' => 'delivery-fate',
        'status' => WebhookDelivery::STATUS_ACCEPTED, 'received_at' => now(),
    ]);
    app(TenantContext::class)->clear();

    return ['tenantId' => (int) $tenant->id, 'connection' => $connection, 'delivery' => $delivery];
}

test('a completed pass marks its delivery delivered', function (): void {
    $f = fateFixture();

    app()->call([new RunIncrementalWorkforceSync($f['tenantId'], (int) $f['connection']->id, (int) $f['delivery']->id), 'handle']);

    $delivery = $f['delivery']->fresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->last_error)->toBeNull();
});

test('a pass that throws marks its delivery failed, keeps the failure text on the row and rethrows', function (): void {
    $f = fateFixture();
    ProviderConnection::query()->whereKey($f['connection']->id)->update(['status' => ProviderConnection::STATUS_RETIRED]);

    expect(fn () => app()->call([new RunIncrementalWorkforceSync($f['tenantId'], (int) $f['connection']->id, (int) $f['delivery']->id), 'handle']))
        ->toThrow(WorkforceSyncException::class, 'is not active');

    $delivery = $f['delivery']->fresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->last_error)->toContain(WorkforceSyncException::class, 'is not active')
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});

test('a job without a recorded delivery touches no ledger row', function (): void {
    $f = fateFixture();

    app()->call([new RunIncrementalWorkforceSync($f['tenantId'], (int) $f['connection']->id), 'handle']);

    expect($f['delivery']->fresh()->status)->toBe(WebhookDelivery::STATUS_ACCEPTED);
});
