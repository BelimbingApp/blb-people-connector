<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function (): void {
    app(TenantContext::class)->clear();
    config()->set('people-connector.webhook.enabled', false);
    config()->set('people-connector.webhook.secrets', []);
    config()->set('people-connector.webhook.max_payload_bytes', 1048576);
    config()->set('people-connector.webhook.delivery_id_ttl_seconds', 86400);
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

function webhookServerHeaders(
    int $connectionId,
    string $body,
    int $timestamp,
    string $secret = 'webhook-test-secret',
    ?string $deliveryId = null,
): array {
    $deliveryId ??= 'delivery-'.Str::uuid();

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PEOPLE_CONNECTOR_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_PEOPLE_CONNECTOR_DELIVERY' => $deliveryId,
        'HTTP_X_PEOPLE_CONNECTOR_SIGNATURE' => hash_hmac(
            'sha256',
            $connectionId."\n".$timestamp."\n".$deliveryId."\n".$body,
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
        server: webhookServerHeaders((int) $connection->id, $body, time(), deliveryId: 'delivery-ledger-1'),
        content: $body,
    );

    $response->assertAccepted()->assertJson(['queued' => true]);

    // The delivery ledger (#223) records the trigger, so an operator can
    // replay it by id later; the job carries the row id, not the payload.
    $delivery = WebhookDelivery::query()->forTenant((int) $connection->tenant_id)->sole();
    expect($delivery->connection_id)->toBe((int) $connection->id)
        ->and($delivery->delivery_id)->toBe('delivery-ledger-1')
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_ACCEPTED)
        ->and($delivery->replayed_from_id)->toBeNull()
        ->and(json_encode($delivery->getAttributes()))->not->toContain('payload-only');
    Bus::assertDispatched(RunIncrementalWorkforceSync::class, function (RunIncrementalWorkforceSync $job) use ($connection, $delivery): bool {
        return $job->tenantId === (int) $connection->tenant_id
            && $job->connectionId === (int) $connection->id
            && $job->deliveryId === (int) $delivery->id;
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

test('a replayed delivery id is refused without enqueueing a second pass', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    Bus::fake();
    $body = webhookBody();
    $headers = webhookServerHeaders((int) $connection->id, $body, time(), deliveryId: 'delivery-replay-1');

    $this->call('POST', "/webhooks/people-connector/{$connection->id}", server: $headers, content: $body)
        ->assertAccepted();
    $this->call('POST', "/webhooks/people-connector/{$connection->id}", server: $headers, content: $body)
        ->assertForbidden()
        ->assertJson(['refused' => 'replayed_delivery']);

    Bus::assertDispatchedTimes(RunIncrementalWorkforceSync::class, 1);
});

test('a payload above the configured limit is refused before dispatch', function (): void {
    $connection = webhookConnection();
    enableWebhookFor($connection);
    config()->set('people-connector.webhook.max_payload_bytes', 32);
    Bus::fake();
    $body = str_repeat('x', 33);

    $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: webhookServerHeaders((int) $connection->id, $body, time()),
        content: $body,
    )->assertStatus(413)->assertJson(['refused' => 'payload_too_large']);

    Bus::assertNothingDispatched();
});

test('a signature made with a sibling connection secret is refused', function (): void {
    $connection = webhookConnection('test.webhook.target');
    $company = Company::factory()->create(['tenant_id' => $connection->tenant_id]);
    $sibling = app(ProviderConnectionStore::class)->configure(
        ProviderScope::company((int) $company->id),
        'test.webhook.sibling',
    );
    $sibling = app(ProviderConnectionStore::class)->activate((int) $sibling->id);
    enableWebhookFor($connection, 'target-secret');
    config()->set('people-connector.webhook.secrets', [
        $sibling->id => 'sibling-secret',
        $connection->id => 'target-secret',
    ]);
    Bus::fake();
    $body = webhookBody();

    $this->call(
        'POST',
        "/webhooks/people-connector/{$connection->id}",
        server: webhookServerHeaders((int) $connection->id, $body, time(), 'sibling-secret'),
        content: $body,
    )->assertForbidden()->assertJson(['refused' => 'invalid_signature']);

    Bus::assertNothingDispatched();
});
