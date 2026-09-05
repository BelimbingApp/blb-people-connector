<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Livewire\Livewire;

/**
 * Reproduces the Octane request boundary the same way the worker does.
 *
 * Octane's FlushTemporaryContainerInstances listener calls
 * forgetScopedInstances() after every request, so the scoped TenantContext is
 * thrown away and rebuilt while singletons carry straight over.
 */
function crossOctaneRequestBoundary(int $nextTenantId): void
{
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($nextTenantId);
}

test('cached provider health does not follow a worker across a tenant boundary', function (): void {
    app(TenantContext::class)->set(1);

    // Resolved while tenant 1 is active, and kept — a singleton behaves this way.
    $store = app(ProviderHealthStore::class);

    $store->record('acme.provider', new ProviderHealth(
        state: ProviderHealthState::Healthy,
        checkedAt: new DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        lastSuccessfulSyncAt: new DateTimeImmutable('2026-09-01T09:30:00+00:00'),
        message: 'Tenant one connection is current.',
    ));

    crossOctaneRequestBoundary(2);

    expect(app(TenantContext::class)->requireTenantId())->toBe(2);

    $leaked = $store->snapshot('acme.provider');

    expect($leaked->state)->toBe(ProviderHealthState::Unknown)
        ->and($leaked->message)->toBe('Health has not been checked yet.')
        ->and($leaked->lastSuccessfulSyncAt)->toBeNull();

    // Tenant two's own evidence must not overwrite tenant one's, either.
    $store->record('acme.provider', new ProviderHealth(
        state: ProviderHealthState::Unavailable,
        checkedAt: new DateTimeImmutable('2026-09-01T11:00:00+00:00'),
        message: 'Tenant two cannot reach the provider.',
    ));

    crossOctaneRequestBoundary(1);

    $restored = $store->snapshot('acme.provider');

    expect($restored->state)->toBe(ProviderHealthState::Healthy)
        ->and($restored->message)->toBe('Tenant one connection is current.');
});

test('the connections page shows each tenant only its own health evidence', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')
        ->andReturn(new ProviderDescriptor('shared.provider', 'Shared Provider', '0.1.0', '1.0.0'));
    $provider->shouldReceive('capabilities')->andReturn(new CapabilitySet([]));
    $provider->shouldReceive('health')->once()->andReturn(new ProviderHealth(
        state: ProviderHealthState::Healthy,
        checkedAt: new DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        lastSuccessfulSyncAt: new DateTimeImmutable('2026-09-01T09:30:00+00:00'),
        message: 'Tenant one connection is current.',
    ));

    $registry = new ProviderRegistry;
    $registry->register($provider);
    app()->instance(ProviderRegistry::class, $registry);
    config()->set('people-connector.active_provider', 'shared.provider');

    app(TenantContext::class)->set(1);

    Livewire::test(Index::class)
        ->call('refreshHealth', 'shared.provider')
        ->assertSee('healthy')
        ->assertSee('Last successful sync')
        ->assertDontSee('Tenant one connection is current.');

    crossOctaneRequestBoundary(2);

    // The adapter is never asked again, so anything but "unknown" here is
    // tenant one's cached evidence being shown to tenant two.
    Livewire::test(Index::class)
        ->assertSee('unknown')
        ->assertDontSee('Tenant one connection is current.')
        ->assertDontSee('Last successful sync');
});
