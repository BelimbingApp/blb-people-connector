<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WebhookDeliveryPolicy;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Bus::fake();
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

/** @return array{tenantId: int, operator: User, connection: ProviderConnection} */
function deliveryPolicyTenant(string $name, WebhookDeliveryPolicy $policy): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(
        ProviderScope::company((int) $company->id),
        'test.delivery-policy',
        new ProviderConnectionMetadata(ProviderConnectionMode::InProcess, deliveryPolicy: $policy),
    )->id);

    return [
        'tenantId' => (int) $tenant->id,
        'operator' => User::factory()->create(['company_id' => $company->id]),
        'connection' => $connection,
    ];
}

function policyDelivery(array $fixture, string $providerDeliveryId): WebhookDelivery
{
    return WebhookDelivery::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'connection_id' => $fixture['connection']->id,
        'delivery_id' => $providerDeliveryId,
        'status' => WebhookDelivery::STATUS_ACCEPTED,
        'received_at' => now(),
    ]);
}

test('a connection without an override stores the safe delivery policy', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Default Delivery Policy Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $connection = app(ProviderConnectionStore::class)->configure(
        ProviderScope::company((int) $company->id),
        'test.default-delivery-policy',
    );

    expect($connection->public_metadata['webhook_delivery_policy'])->toBe([
        'max_attempts' => 3,
        'backoff_seconds' => [60, 300],
    ]);
});

test('a connection delivery policy dead letters the final failed attempt instead of retrying forever', function (): void {
    $fixture = deliveryPolicyTenant('Delivery Policy Tenant', new WebhookDeliveryPolicy(2, [17]));
    $delivery = policyDelivery($fixture, 'policy-attempts');
    ProviderConnection::query()->whereKey($fixture['connection']->id)->update(['status' => ProviderConnection::STATUS_RETIRED]);

    $first = RunIncrementalWorkforceSync::forDelivery($fixture['connection'], (int) $delivery->id);
    $first->withFakeQueueInteractions();
    expect($first->tries)->toBe(2)
        ->and($first->backoff())->toBe([17])
        ->and(fn () => app()->call([$first, 'handle']))
        ->toThrow(WorkforceSyncException::class);
    $first->assertNotFailed();

    $final = RunIncrementalWorkforceSync::forDelivery($fixture['connection'], (int) $delivery->id);
    $final->withFakeQueueInteractions();
    expect($final->job)->toBeInstanceOf(FakeJob::class);
    $final->job->attempts = 2;

    app()->call([$final, 'handle']);

    $final->assertFailedWith(WorkforceSyncException::class);
    $deadLetter = $delivery->fresh();
    expect($deadLetter->status)->toBe(WebhookDelivery::STATUS_DEAD_LETTERED)
        ->and($deadLetter->attempts)->toBe(2)
        ->and($fixture['connection']->public_metadata['webhook_delivery_policy'])->toBe([
            'max_attempts' => 2,
            'backoff_seconds' => [17],
        ]);
});

test('the dead letters command lists and replays only the operator tenant', function (): void {
    $target = deliveryPolicyTenant('Target Dead Letter Tenant', new WebhookDeliveryPolicy(2, [17]));
    $targetDelivery = policyDelivery($target, 'target-dead-letter');
    $targetDelivery->forceFill(['status' => WebhookDelivery::STATUS_DEAD_LETTERED, 'attempts' => 2])->save();

    $foreign = deliveryPolicyTenant('Foreign Dead Letter Tenant', new WebhookDeliveryPolicy(2, [17]));
    $foreignDelivery = policyDelivery($foreign, 'foreign-dead-letter');
    $foreignDelivery->forceFill(['status' => WebhookDelivery::STATUS_DEAD_LETTERED, 'attempts' => 2])->save();

    expect(Artisan::call('connector:webhook:dead-letters', [
        '--tenant' => $target['tenantId'],
        '--as' => $target['operator']->id,
        '--replay' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('target-dead-letter', 'Replayed 1 dead-lettered delivery')
        ->not->toContain('foreign-dead-letter')
        ->and(WebhookDelivery::query()->where('replayed_from_id', $targetDelivery->id)->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('replayed_from_id', $foreignDelivery->id)->count())->toBe(0);

    Bus::assertDispatchedTimes(RunIncrementalWorkforceSync::class, 1);
});
