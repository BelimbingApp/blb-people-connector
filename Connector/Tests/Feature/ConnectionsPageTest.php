<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Livewire\Livewire;

beforeEach(function (): void {
    app(TenantContext::class)->set(1);
});

test('connections page renders the honest disconnected state when no adapter is configured', function (): void {
    app()->forgetInstance(ProviderRegistry::class);
    config()->set('people-connector.active_provider', null);

    Livewire::test(Index::class)
        ->assertViewHas('providers', [])
        ->assertSee('No People provider is configured');
});

test('connections page distinguishes a missing configured adapter from an unconfigured connector', function (): void {
    app()->forgetInstance(ProviderRegistry::class);
    config()->set('people-connector.active_provider', 'missing.adapter');

    Livewire::test(Index::class)
        ->assertViewHas('activeProviderId', null)
        ->assertViewHas('configuredProviderId', 'missing.adapter')
        ->assertSee('configured People provider missing.adapter is unavailable');
});

test('connections page renders cached health without invoking an adapter health check', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')
        ->andReturn(new ProviderDescriptor('test.provider', 'Test Provider', '0.1.0', '1.0.0'));
    $provider->shouldReceive('capabilities')->andReturn(new CapabilitySet([]));
    $provider->shouldNotReceive('health');

    $registry = new ProviderRegistry;
    $registry->register($provider);
    app()->instance(ProviderRegistry::class, $registry);
    config()->set('people-connector.active_provider', 'test.provider');

    Livewire::test(Index::class)
        ->assertViewHas('activeProviderId', 'test.provider')
        ->assertSee('Test Provider')
        ->assertSee('unknown');
});

test('connections page refreshes and preserves provider health evidence on demand', function (): void {
    $checkedAt = new DateTimeImmutable('2026-08-31T01:00:00+00:00');
    $lastSyncAt = new DateTimeImmutable('2026-08-31T00:30:00+00:00');
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')
        ->andReturn(new ProviderDescriptor('refresh.provider', 'Refresh Provider', '0.1.0', '1.0.0'));
    $provider->shouldReceive('capabilities')->andReturn(new CapabilitySet([]));
    $provider->shouldReceive('health')->once()->ordered()->andReturn(
        new ProviderHealth(ProviderHealthState::Healthy, $checkedAt, $lastSyncAt, 'Connection is current.'),
    );
    $provider->shouldReceive('health')->once()->ordered()->andThrow(new RuntimeException('provider unavailable'));

    $registry = new ProviderRegistry;
    $registry->register($provider);
    app()->instance(ProviderRegistry::class, $registry);
    config()->set('people-connector.active_provider', 'refresh.provider');

    Livewire::test(Index::class)
        ->assertSee('unknown')
        ->call('refreshHealth', 'refresh.provider')
        ->assertSee('healthy')
        ->assertSee('Last successful sync')
        ->assertSee('Connection is current.')
        ->call('refreshHealth', 'refresh.provider')
        ->assertSee('unavailable')
        ->assertSee('Last successful sync')
        ->assertSee('The provider health check failed.');
});

test('connections page warns about a missing configured adapter even when another adapter is installed', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')
        ->andReturn(new ProviderDescriptor('other.provider', 'Other Provider', '0.1.0', '1.0.0'));
    $provider->shouldReceive('capabilities')->andReturn(new CapabilitySet([]));
    $provider->shouldNotReceive('health');

    $registry = new ProviderRegistry;
    $registry->register($provider);
    app()->instance(ProviderRegistry::class, $registry);
    config()->set('people-connector.active_provider', 'missing.adapter');

    Livewire::test(Index::class)
        ->assertSee('configured People provider missing.adapter is unavailable')
        ->assertSee('Other Provider');
});
