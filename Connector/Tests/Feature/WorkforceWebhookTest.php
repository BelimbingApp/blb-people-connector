<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
    config()->set('people-connector.webhook.enabled', false);
    config()->set('people-connector.webhook.secrets', []);
});

function webhookConnection(string $provider = 'test.webhook'): ProviderConnection
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Webhook Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $connection = app(ProviderConnectionStore::class)->configure(
        ProviderScope::company((int) $company->id),
        $provider,
    );

    return app(ProviderConnectionStore::class)->activate((int) $connection->id);
}

function webhookBody(string $payload = '{"event":"workforce.changed"}'): string
{
    return $payload;
}

function webhookServerHeaders(int $connectionId, string $body, int $timestamp, string $secret = 'webhook-test-secret'): array
{
    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PEOPLE_CONNECTOR_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_PEOPLE_CONNECTOR_SIGNATURE' => hash_hmac(
            'sha256',
            $connectionId."\n".$timestamp."\n".$body,
            $secret,
        ),
    ];
}

function enableWebhookFor(ProviderConnection $connection, string $secret = 'webhook-test-secret'): void
{
    config()->set('people-connector.webhook.enabled', true);
    config()->set('people-connector.webhook.timestamp_tolerance_seconds', 300);
    config()->set("people-connector.webhook.secrets.{$connection->id}", $secret);
}

test('the webhook is disabled by default', function (): void {
    $response = $this->post('/webhooks/people-connector/999999');

    $response->assertNotFound()->assertJson(['refused' => 'disabled']);
});

test('a valid webhook queues one incremental pass without reading the payload as projections', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    Bus::fake();
    $body = webhookBody('{"employee":{"id":"payload-only"}}');
    $before = DB::table('people_connector_connector_workforce_entities')->count();

    $response = $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: webhookServerHeaders((int) $connection->id, $body, time()),
        content: $body,
    );

    $response->assertAccepted()->assertJson(['queued' => true]);
    Bus::assertDispatched(RunIncrementalWorkforceSync::class, function (RunIncrementalWorkforceSync $job) use ($connection): bool {
        return $job->tenantId === (int) $connection->tenant_id
            && $job->connectionId === (int) $connection->id;
    });
    expect(DB::table('people_connector_connector_workforce_entities')->count())->toBe($before);
});

test('a bad webhook signature is refused before dispatch', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    Bus::fake();
    $body = webhookBody();
    $headers = webhookServerHeaders((int) $connection->id, $body, time());
    $headers['HTTP_X_PEOPLE_CONNECTOR_SIGNATURE'] = str_repeat('0', 64);

    $response = $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: $headers,
        content: $body,
    );

    $response->assertForbidden()->assertJson(['refused' => 'invalid_signature']);
    Bus::assertNothingDispatched();
});

test('a stale webhook timestamp is refused before dispatch', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    Bus::fake();
    $body = webhookBody();
    $timestamp = time() - 301;

    $response = $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: webhookServerHeaders((int) $connection->id, $body, $timestamp),
        content: $body,
    );

    $response->assertForbidden()->assertJson(['refused' => 'stale_timestamp']);
    Bus::assertNothingDispatched();
});

test('an unknown connection is refused before signature verification', function (): void {
    app(TenantContext::class)->set((int) createTenantWithCompany(['name' => 'Unknown Webhook Tenant'])[0]->id);
    config()->set('people-connector.webhook.enabled', true);
    Bus::fake();

    $response = $this->post('/webhooks/people-connector/999999');

    $response->assertForbidden()->assertJson(['refused' => 'unknown_connection']);
    Bus::assertNothingDispatched();
});

test('a connection from another tenant is refused', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    [, $otherCompany] = createTenantWithCompany(['name' => 'Other Webhook Tenant']);
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
    $this->actingAs($otherUser);
    Bus::fake();
    $body = webhookBody();

    $response = $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: webhookServerHeaders((int) $connection->id, $body, time()),
        content: $body,
    );

    $response->assertForbidden()->assertJson(['refused' => 'unknown_connection']);
    Bus::assertNothingDispatched();
});
