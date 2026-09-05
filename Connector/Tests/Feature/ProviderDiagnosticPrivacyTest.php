<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthMonitor;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('credential model array and JSON serialization omit the stored reference', function (): void {
    $record = new ProviderCredentialRecord([
        'credential_id' => 'pcred_public',
        'key_id' => 'rotation-2026',
        'secret_reference' => 'base-integration:private-reference',
    ]);

    expect($record->getAttribute('secret_reference'))->toBe('base-integration:private-reference')
        ->and($record->toArray())->toBe(['credential_id' => 'pcred_public', 'key_id' => 'rotation-2026'])
        ->and(json_decode($record->toJson(), true))->toBe($record->toArray());
});

test('connection endpoint refusals never echo credentials into exception messages', function (string $endpoint): void {
    try {
        new ProviderConnectionMetadata(ProviderConnectionMode::RemoteHttp, $endpoint);
        test()->fail('Credential-bearing endpoint was accepted.');
    } catch (InvalidProviderConfigurationException $exception) {
        expect($exception->getMessage())->toBe('Provider endpoints must be credential-free HTTPS origins without paths or query parameters.')
            ->not->toContain('private-reference');
    }
})->with([
    'user info' => ['https://private-reference@provider.example.test'],
    'password' => ['https://user:private-reference@provider.example.test'],
    'path' => ['https://provider.example.test/private-reference'],
    'query' => ['https://provider.example.test?secret_reference=private-reference'],
    'fragment' => ['https://provider.example.test#private-reference'],
]);

test('reconciliation identifiers refuse raw diagnostic payloads without echoing them', function (string $field): void {
    try {
        new ReconciliationIssueDetails(...[$field => '{"secret_reference":"base-integration:private-reference"}']);
        test()->fail('Raw diagnostic payload was accepted.');
    } catch (InvalidReconciliationIssueException $exception) {
        expect($exception->getMessage())->toBe('Reconciliation detail identifiers require a stable lowercase value.')
            ->not->toContain('private-reference');
    }
})->with(['field', 'reasonCode']);

test('provider health responses retain status and timestamps without caching raw adapter messages', function (ProviderHealthState $state): void {
    [$tenant] = createTenantWithCompany(['name' => 'Health Diagnostic Privacy']);
    app(TenantContext::class)->set((int) $tenant->id);
    $checkedAt = new DateTimeImmutable('2026-09-05T00:00:00+00:00');
    $lastSyncAt = $checkedAt->modify('-1 hour');
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor('privacy.provider', 'Privacy Provider', '1.0.0', '1.0.0'));
    $provider->shouldReceive('health')->once()->andReturn(new ProviderHealth(
        $state, $checkedAt, $lastSyncAt,
        '{"secret_reference":"base-integration:private-reference","medical":"private-payload"}',
    ));

    $health = app(ProviderHealthMonitor::class)->refresh($provider);
    $cached = app(ProviderHealthStore::class)->snapshot('privacy.provider');

    expect([$health->state, $health->checkedAt, $health->lastSuccessfulSyncAt])->toBe([$state, $checkedAt, $lastSyncAt])
        ->and($health->message)->toBeNull()
        ->and($cached->message)->toBeNull()
        ->and(serialize($cached))->not->toContain('private-reference')->not->toContain('private-payload');
})->with(ProviderHealthState::cases());

test('provider health exceptions never enter the cached diagnostic', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Health Exception Privacy']);
    app(TenantContext::class)->set((int) $tenant->id);
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor('privacy.provider', 'Privacy Provider', '1.0.0', '1.0.0'));
    $provider->shouldReceive('health')->once()->andThrow(new RuntimeException('base-integration:private-reference private-payload'));

    $health = app(ProviderHealthMonitor::class)->refresh($provider);

    expect($health->state)->toBe(ProviderHealthState::Unavailable)
        ->and($health->message)->toBe('The provider health check failed. Review the adapter diagnostics.')
        ->and(serialize(app(ProviderHealthStore::class)->snapshot('privacy.provider')))->not->toContain('private-reference')->not->toContain('private-payload');
});

test('sync command suppresses raw adapter exceptions while retaining failure and connection identity', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sync Diagnostic Privacy']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), 'privacy.provider');
    $store->activate((int) $connection->id);
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->once()->ordered()->andReturn(
        new ProviderDescriptor('privacy.provider', 'Privacy Provider', '1.0.0', '1.0.0'),
    );
    $provider->shouldReceive('descriptor')->once()->ordered()->andThrow(
        new RuntimeException('base-integration:private-reference private-payload'),
    );
    app(ProviderRegistry::class)->register($provider);

    $exit = Artisan::call('people-connector:sync', ['connection' => (int) $connection->id, '--bootstrap' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("Connection {$connection->id}: synchronization failed.")
        ->not->toContain('private-reference')->not->toContain('private-payload')
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});

test('legacy cached adapter messages cannot survive the diagnostic boundary change', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Legacy Health Privacy']);
    app(TenantContext::class)->set((int) $tenant->id);
    $providerId = 'privacy.provider';
    $legacyKey = sprintf('people-connector:tenant:%d:provider:%s:health', $tenant->id, hash('sha256', $providerId));
    app(Repository::class)->forever($legacyKey, new ProviderHealth(
        ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-05T00:00:00+00:00'),
        message: 'base-integration:private-reference private-payload',
    ));

    $health = app(ProviderHealthStore::class)->snapshot($providerId);

    expect($health->state)->toBe(ProviderHealthState::Unknown)
        ->and($health->checkedAt)->toBeNull()
        ->and($health->message)->toBe('Health has not been checked yet.');
});
