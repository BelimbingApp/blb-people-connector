<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\WebhookDeliveryFailure;
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
function fateFixture(string $provider = FirstPartyPeopleAdapter::ID, string $name = 'Fate Tenant'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    if (app(ProviderRegistry::class)->find($provider) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }
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
        ->and($delivery->failure_reason)->toBeNull()
        ->and($delivery->failure_class)->toBeNull();
});

test('a pass that throws marks its delivery failed with a reason code and class, never the message, and rethrows', function (): void {
    $f = fateFixture();
    ProviderConnection::query()->whereKey($f['connection']->id)->update(['status' => ProviderConnection::STATUS_RETIRED]);

    expect(fn () => app()->call([new RunIncrementalWorkforceSync($f['tenantId'], (int) $f['connection']->id, (int) $f['delivery']->id), 'handle']))
        ->toThrow(WorkforceSyncException::class, 'is not active');

    $delivery = $f['delivery']->fresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->failure_reason)->toBe(WebhookDeliveryFailure::SyncRefused)
        ->and($delivery->failure_class)->toBe(WorkforceSyncException::class)
        ->and(json_encode($delivery->getAttributes()))->not->toContain('is not active')
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});

test('a job never marks another tenant\'s delivery row, even by id', function (): void {
    $a = fateFixture(name: 'Fate Tenant A');
    $b = fateFixture(name: 'Fate Tenant B');

    // Tenant A's pass completes, handed tenant B's delivery id: the row it
    // reports to is looked up inside A, so B's row is untouched.
    app()->call([new RunIncrementalWorkforceSync($a['tenantId'], (int) $a['connection']->id, (int) $b['delivery']->id), 'handle']);

    expect($b['delivery']->fresh()->status)->toBe(WebhookDelivery::STATUS_ACCEPTED)
        ->and($b['delivery']->fresh()->attempts)->toBe(0)
        ->and($a['delivery']->fresh()->status)->toBe(WebhookDelivery::STATUS_ACCEPTED);
});

test('a job without a recorded delivery touches no ledger row', function (): void {
    $f = fateFixture();

    app()->call([new RunIncrementalWorkforceSync($f['tenantId'], (int) $f['connection']->id), 'handle']);

    expect($f['delivery']->fresh()->status)->toBe(WebhookDelivery::STATUS_ACCEPTED);
});
