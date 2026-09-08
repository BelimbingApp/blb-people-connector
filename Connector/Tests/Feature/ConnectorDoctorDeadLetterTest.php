<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WebhookDeliveryFailure;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\CorruptWorkforcePageException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctor;
use App\Domains\PeopleConnector\Connector\Services\DeadLetterService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * connector:doctor (#271): a dead-lettered webhook delivery and a parked
 * sync page are each their own red row, in the operator's own tenant only.
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');
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
function doctorDlTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    if (app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), FirstPartyPeopleAdapter::ID)->id);
    // A usable credential keeps the connection's provider_credential_expiry row (#296) green.
    ProviderCredentialRecord::query()->create([
        'tenant_id' => (int) $connection->tenant_id, 'connection_id' => (int) $connection->id, 'provider_id' => FirstPartyPeopleAdapter::ID,
        'key_id' => 'fixture-key', 'secret_reference' => 'base-integration:fixture', 'audience' => 'provider',
        'scopes' => ['workforce:read'], 'issued_at' => '2020-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00',
    ]);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => $connection];
}

function doctorDlDelivery(array $tenant, string $label, int $failedMinutesAgo): WebhookDelivery
{
    return WebhookDelivery::query()->create([
        'tenant_id' => $tenant['tenantId'], 'connection_id' => $tenant['connection']->id, 'delivery_id' => 'delivery-'.$label,
        'status' => WebhookDelivery::STATUS_DEAD_LETTERED, 'attempts' => 3,
        'failure_reason' => WebhookDeliveryFailure::PageCorrupt, 'failure_class' => CorruptWorkforcePageException::class,
        'received_at' => now()->subMinutes($failedMinutesAgo + 5), 'failed_at' => now()->subMinutes($failedMinutesAgo),
    ]);
}

function doctorDlParkedPage(array $tenant, string $label): ReconciliationIssue
{
    app(TenantContext::class)->set($tenant['tenantId']);

    return app(ReconciliationIssueStore::class)->report(
        (int) $tenant['connection']->id, 'sync:page:'.$label, WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER,
        new ReconciliationIssueDetails(reasonCode: 'every_record_refused'), WorkforceResourceType::Employee->value, 'PAGE-'.$label,
    );
}

/** @return array<string, array{check: string, status: string, count: int, detail: string}> rows by check name */
function doctorDlRows(array $tenant, array $extra = []): array
{
    Artisan::call('connector:doctor', ['--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, '--json' => true, ...$extra]);

    return collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('check')->all();
}

function doctorDlExit(array $tenant, array $extra = []): int
{
    return Artisan::call('connector:doctor', ['--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, ...$extra]);
}

test('a dead-lettered delivery turns webhook_dead_letters red and a successful replay turns it green', function (): void {
    $this->travelTo('2026-09-07 10:00:00');
    $tenant = doctorDlTenant('Doctor Dead Letter Tenant');
    $dead = doctorDlDelivery($tenant, 'dead', 30);

    expect(doctorDlExit($tenant))->toBe(1)
        ->and(Artisan::output())->toContain('webhook_dead_letters', 'red');
    $rows = doctorDlRows($tenant);
    expect($rows['webhook_dead_letters'])->toBe(['check' => 'webhook_dead_letters', 'status' => 'red', 'count' => 1, 'detail' => '1 dead-lettered, oldest failed_at 2026-09-07T09:30:00+00:00'])
        ->and($rows['webhook_deliveries']['status'])->toBe('green');

    expect(Artisan::call('connector:webhook:replay', ['delivery' => $dead->id, '--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id]))->toBe(0)
        ->and($dead->fresh()->status)->toBe(WebhookDelivery::STATUS_DEAD_LETTERED);

    expect(doctorDlExit($tenant))->toBe(0);
    $rows = doctorDlRows($tenant);
    expect($rows['webhook_dead_letters'])->toBe(['check' => 'webhook_dead_letters', 'status' => 'green', 'count' => 0, 'detail' => '0 dead-lettered']);
});

test('a parked page turns sync_dead_letters red while reconciliation_drift counts it, and a requeue turns the row green', function (): void {
    $tenant = doctorDlTenant('Doctor Parked Page Tenant');
    $parked = doctorDlParkedPage($tenant, 'stuck');
    app(ReconciliationIssueStore::class)->report((int) $tenant['connection']->id, 'sync:employee:review', 'sync_conflict', new ReconciliationIssueDetails(reasonCode: 'review_required'), WorkforceResourceType::Employee->value, 'EMP-REVIEW');

    expect(doctorDlExit($tenant))->toBe(1)
        ->and(Artisan::output())->toContain('sync_dead_letters', 'red');
    $rows = doctorDlRows($tenant);
    expect($rows['sync_dead_letters'])->toBe(['check' => 'sync_dead_letters', 'status' => 'red', 'count' => 1, 'detail' => '1 parked pages across 1 connections'])
        ->and($rows['reconciliation_drift'])->toBe(['check' => 'reconciliation_drift', 'status' => 'red', 'count' => 2, 'detail' => '2 open']);

    app(TenantContext::class)->set($tenant['tenantId']);
    expect(app(DeadLetterService::class)->requeue((int) $tenant['connection']->id, (int) $parked->id, 'review-271')->status)->toBe(ReconciliationIssue::STATUS_RESOLVED);

    $rows = doctorDlRows($tenant);
    expect($rows['sync_dead_letters'])->toBe(['check' => 'sync_dead_letters', 'status' => 'green', 'count' => 0, 'detail' => '0 parked pages across 0 connections'])
        ->and($rows['reconciliation_drift']['count'])->toBe(1);
});

test('another tenants dead letters leave both rows green here', function (): void {
    $tenant = doctorDlTenant('Doctor Dead Letter Home Tenant');
    $other = doctorDlTenant('Doctor Dead Letter Foreign Tenant');
    doctorDlDelivery($other, 'foreign', 10);
    doctorDlParkedPage($other, 'foreign');

    $home = doctorDlRows($tenant);
    expect($home['webhook_dead_letters'])->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($home['sync_dead_letters'])->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($home['reconciliation_drift']['count'])->toBe(0);

    $foreign = doctorDlRows($other);
    expect($foreign['webhook_dead_letters'])->toMatchArray(['status' => 'red', 'count' => 1])
        ->and($foreign['sync_dead_letters'])->toMatchArray(['status' => 'red', 'count' => 1]);
});

test('record writes one snapshot per dead-letter check and history returns the latest of each', function (): void {
    $tenant = doctorDlTenant('Doctor Dead Letter History Tenant');
    doctorDlDelivery($tenant, 'recorded', 10);
    doctorDlParkedPage($tenant, 'recorded');
    $snapshots = fn () => DB::table('people_connector_connector_doctor_snapshots')->where('tenant_id', $tenant['tenantId']);
    $checksPerRun = count(app(ConnectorDoctor::class)->inspect(Actor::forUser($tenant['operator']))->checks);

    $this->travelTo('2026-09-07 10:00:00');
    expect(doctorDlExit($tenant, ['--record' => true]))->toBe(1)
        ->and($snapshots()->count())->toBe($checksPerRun)
        ->and($snapshots()->whereIn('check', ['webhook_dead_letters', 'sync_dead_letters'])->pluck('count', 'check')->sortKeys()->all())->toBe(['sync_dead_letters' => 1, 'webhook_dead_letters' => 1]);

    WebhookDelivery::query()->forTenant($tenant['tenantId'])->update(['status' => WebhookDelivery::STATUS_DELIVERED]);
    $this->travelTo('2026-09-07 11:00:00');
    expect(doctorDlExit($tenant, ['--record' => true]))->toBe(1)
        ->and($snapshots()->count())->toBe(2 * $checksPerRun);

    $history = doctorDlRows($tenant, ['--history' => 1]);
    expect($history)->toHaveCount($checksPerRun)
        ->and($history['webhook_dead_letters'])->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($history['sync_dead_letters'])->toMatchArray(['status' => 'red', 'count' => 1]);
});

test('the doctor writes nothing but snapshots: delivery and issue tables are unchanged by inspect', function (): void {
    $tenant = doctorDlTenant('Doctor Dead Letter Read Only Tenant');
    doctorDlDelivery($tenant, 'untouched', 10);
    doctorDlParkedPage($tenant, 'untouched');
    // Every column of every row, so a touched timestamp or counter shows as well as a missing row.
    $fingerprint = fn (): array => [
        DB::table((new WebhookDelivery)->getTable())->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        DB::table((new ReconciliationIssue)->getTable())->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
    $before = $fingerprint();
    $this->travelTo(now()->addMinutes(5));

    $report = app(ConnectorDoctor::class)->inspect(Actor::forUser($tenant['operator']));

    expect(collect($report->checks)->keyBy('check')->only(['webhook_dead_letters', 'sync_dead_letters'])->pluck('status')->all())->toBe(['red', 'red'])
        ->and($fingerprint())->toBe($before)
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(ReconciliationIssue::query()->count())->toBe(1);
});
