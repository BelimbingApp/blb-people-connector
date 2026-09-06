<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WebhookDeliveryFailure;
use App\Domains\PeopleConnector\Connector\Exceptions\CorruptWorkforcePageException;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

/**
 * connector:webhook:replay (#223): one failed delivery, re-sent for the
 * operator's own tenant, with an audit row; anything else exits non-zero
 * and sends nothing.
 */
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
function webhookReplayTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), 'test.replay')->id);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => $connection];
}

function webhookReplayDelivery(array $tenant, string $status = WebhookDelivery::STATUS_FAILED): WebhookDelivery
{
    return WebhookDelivery::query()->create([
        'tenant_id' => $tenant['tenantId'], 'connection_id' => $tenant['connection']->id,
        'delivery_id' => 'delivery-'.$status.'-'.$tenant['tenantId'], 'status' => $status,
        'attempts' => 3,
        'failure_reason' => $status === WebhookDelivery::STATUS_FAILED ? WebhookDeliveryFailure::PageCorrupt : null,
        'failure_class' => $status === WebhookDelivery::STATUS_FAILED ? CorruptWorkforcePageException::class : null,
        'received_at' => now()->subHour(),
    ]);
}

function webhookReplayCall(array $tenant, WebhookDelivery $delivery, array $extra = []): int
{
    return Artisan::call('connector:webhook:replay', ['delivery' => $delivery->id, '--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, ...$extra]);
}

test('replaying a failed delivery dispatches exactly one new pass and records the audit row', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $failed = webhookReplayDelivery($tenant);

    expect(webhookReplayCall($tenant, $failed))->toBe(0);

    $replay = WebhookDelivery::query()->where('replayed_from_id', $failed->id)->sole();
    expect($replay->status)->toBe(WebhookDelivery::STATUS_ACCEPTED)
        ->and($replay->tenant_id)->toBe($tenant['tenantId'])
        ->and($replay->connection_id)->toBe((int) $tenant['connection']->id)
        ->and($replay->delivery_id)->toBe($failed->delivery_id)
        ->and($failed->fresh()->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and(Artisan::output())->toContain("Replayed webhook delivery {$failed->id} as delivery {$replay->id}", "operator {$tenant['operator']->id}");

    Bus::assertDispatchedTimes(RunIncrementalWorkforceSync::class, 1);
    Bus::assertDispatched(RunIncrementalWorkforceSync::class, fn (RunIncrementalWorkforceSync $job): bool => $job->tenantId === $tenant['tenantId']
        && $job->connectionId === (int) $tenant['connection']->id
        && $job->deliveryId === (int) $replay->id
        && $job->queue === RunIncrementalWorkforceSync::QUEUE);

    $audit = OperatorAudit::query()->forTenant($tenant['tenantId'])->sole();
    expect($audit->operation)->toBe(OperatorAuditOperation::WebhookReplayed)
        ->and($audit->actor_id)->toBe((int) $tenant['operator']->id)
        ->and($audit->connection_id)->toBe((int) $tenant['connection']->id)
        ->and($audit->before_summary['delivery'] ?? null)->toBe($failed->id)
        ->and($audit->before_summary['provider_delivery_id'] ?? null)->toBe($failed->delivery_id)
        ->and($audit->before_summary['failure_reason'] ?? null)->toBe('page_corrupt')
        ->and($audit->before_summary)->not->toHaveKey('failure_class')
        ->and($audit->after_summary['delivery'] ?? null)->toBe($replay->id);
});

test('a delivery from another tenant is not found and nothing is sent', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $other = webhookReplayTenant('Other Replay Tenant');
    $foreign = webhookReplayDelivery($other);

    expect(webhookReplayCall($tenant, $foreign))->toBe(1)
        ->and(Artisan::output())->toContain('The webhook delivery was not found in the current tenant.')
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(OperatorAudit::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
});

test('an already delivered id exits non-zero and dispatches nothing', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $delivered = webhookReplayDelivery($tenant, WebhookDelivery::STATUS_DELIVERED);

    expect(webhookReplayCall($tenant, $delivered))->toBe(1)
        ->and(Artisan::output())->toContain("Webhook delivery {$delivered->id} is delivered; only a failed delivery can be replayed.")
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(OperatorAudit::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
});

test('a dry run prints what would be sent and sends and records nothing', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $failed = webhookReplayDelivery($tenant);

    expect(webhookReplayCall($tenant, $failed, ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Dry run', (string) $failed->id, $failed->delivery_id, 'page_corrupt', CorruptWorkforcePageException::class, (string) $tenant['connection']->id, RunIncrementalWorkforceSync::class, RunIncrementalWorkforceSync::QUEUE)
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(OperatorAudit::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
});

test('a replay runs as a named operator inside the tenant', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $other = webhookReplayTenant('Other Replay Tenant');
    $failed = webhookReplayDelivery($tenant);

    expect(Artisan::call('connector:webhook:replay', ['delivery' => $failed->id, '--tenant' => $tenant['tenantId']]))->toBe(1)
        ->and(Artisan::output())->toContain('pass --as=<user id>')
        ->and(Artisan::call('connector:webhook:replay', ['delivery' => $failed->id, '--tenant' => $tenant['tenantId'], '--as' => $other['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('requires an operator inside the current tenant')
        ->and(WebhookDelivery::query()->count())->toBe(1);

    Bus::assertNothingDispatched();
});

test('a failed delivery on a retired connection is refused rather than re-sent to fail again', function (): void {
    $tenant = webhookReplayTenant('Replay Tenant');
    $failed = webhookReplayDelivery($tenant);
    ProviderConnection::query()->whereKey($tenant['connection']->id)->update(['status' => ProviderConnection::STATUS_RETIRED]);

    expect(webhookReplayCall($tenant, $failed))->toBe(1)
        ->and(Artisan::output())->toContain("Provider connection {$tenant['connection']->id} is not active")
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(OperatorAudit::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
});
