<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ConnectorDoctorAlert;
use App\Domains\PeopleConnector\Connector\Notifications\ConnectorDoctorAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('people-connector.doctor.alert_channel', 'database');
    Notification::fake();
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

/** @return array{0: int, 1: User} */
function doctorAlertTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return [(int) $tenant->id, User::factory()->create(['company_id' => $company->id])];
}

/** A stale webhook delivery is the one red the doctor can be put into, and taken out of, from a test. */
function doctorAlertStaleWebhook(int $tenantId): void
{
    Queue::connection('database')->pushOn(RunIncrementalWorkforceSync::QUEUE, new RunIncrementalWorkforceSync($tenantId, 999));
    DB::table('jobs')->latest('id')->limit(1)->update(['created_at' => now()->subSeconds(7200)->timestamp]);
}

function doctorAlertRun(int $tenantId, User $operator, string $at): int
{
    test()->travelTo($at);

    return Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--record' => true, '--alert' => true]);
}

/** @return list<array<string, mixed>> the alert payloads this operator received, in order */
function doctorAlertPayloads(User $operator): array
{
    $payloads = [];
    Notification::assertSentTo($operator, ConnectorDoctorAlertNotification::class, function (ConnectorDoctorAlertNotification $notification) use (&$payloads, $operator): bool {
        $payloads[] = $notification->toArray($operator);

        return true;
    });

    return $payloads;
}

test('a red once stays quiet, red twice alerts once naming the check, red a third time is still one alert', function (): void {
    [$tenantId, $operator] = doctorAlertTenant('Doctor Alert Tenant');
    doctorAlertStaleWebhook($tenantId);

    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 10:00:00'))->toBe(1);
    Notification::assertNothingSent();
    expect(DB::table('people_connector_connector_doctor_snapshots')->where('tenant_id', $tenantId)->count())->toBe(7);

    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 11:00:00'))->toBe(1)
        ->and(Artisan::output())->toContain('webhook_deliveries');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);
    $payloads = doctorAlertPayloads($operator);
    expect($payloads[0])->toMatchArray(['tenant_id' => $tenantId, 'check' => 'webhook_deliveries', 'kind' => 'red', 'count' => 1, 'detail' => '1 stale'])
        ->and($payloads[0]['first_red_at'])->toBe('2026-09-07T10:00:00+00:00');

    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 12:00:00'))->toBe(1);
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);
    expect(ConnectorDoctorAlert::query()->forTenant($tenantId)->count())->toBe(1);
});

test('green after red-red sends one recovery alert, then silence, and a later red is a new incident', function (): void {
    [$tenantId, $operator] = doctorAlertTenant('Doctor Recovery Tenant');
    doctorAlertStaleWebhook($tenantId);
    doctorAlertRun($tenantId, $operator, '2026-09-07 10:00:00');
    doctorAlertRun($tenantId, $operator, '2026-09-07 11:00:00');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);

    DB::table('jobs')->delete();
    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 12:00:00'))->toBe(0);
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 2);
    $payloads = doctorAlertPayloads($operator);
    expect($payloads[1])->toMatchArray(['check' => 'webhook_deliveries', 'kind' => 'recovered', 'count' => 0])
        ->and($payloads[1]['first_red_at'])->toBe('2026-09-07T10:00:00+00:00');

    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 13:00:00'))->toBe(0);
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 2);

    // A single red between greens is transient: no alert, and no recovery for it either.
    doctorAlertStaleWebhook($tenantId);
    doctorAlertRun($tenantId, $operator, '2026-09-07 14:00:00');
    DB::table('jobs')->delete();
    doctorAlertRun($tenantId, $operator, '2026-09-07 15:00:00');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 2);

    doctorAlertStaleWebhook($tenantId);
    doctorAlertRun($tenantId, $operator, '2026-09-07 16:00:00');
    doctorAlertRun($tenantId, $operator, '2026-09-07 17:00:00');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 3);
    expect(doctorAlertPayloads($operator)[2]['first_red_at'])->toBe('2026-09-07T16:00:00+00:00');
});

test('two tenants red on the same check are alerted separately and never see each other\'s detail', function (): void {
    [$tenantId, $operator] = doctorAlertTenant('Doctor Alert Tenant A');
    [$otherTenantId, $otherOperator] = doctorAlertTenant('Doctor Alert Tenant B');
    doctorAlertStaleWebhook($tenantId);
    doctorAlertStaleWebhook($tenantId);

    // Tenant B turns red one run after tenant A: only A's own history may
    // make B's first red look like a second one.
    doctorAlertRun($tenantId, $operator, '2026-09-07 10:00:00');
    doctorAlertRun($otherTenantId, $otherOperator, '2026-09-07 10:00:00');
    doctorAlertStaleWebhook($otherTenantId);
    doctorAlertRun($tenantId, $operator, '2026-09-07 11:00:00');
    doctorAlertRun($otherTenantId, $otherOperator, '2026-09-07 11:00:00');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);
    Notification::assertNotSentTo($otherOperator, ConnectorDoctorAlertNotification::class);

    doctorAlertRun($tenantId, $operator, '2026-09-07 12:00:00');
    doctorAlertRun($otherTenantId, $otherOperator, '2026-09-07 12:00:00');
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);
    Notification::assertSentToTimes($otherOperator, ConnectorDoctorAlertNotification::class, 1);
    expect(doctorAlertPayloads($operator)[0])->toMatchArray(['tenant_id' => $tenantId, 'check' => 'webhook_deliveries', 'detail' => '2 stale', 'first_red_at' => '2026-09-07T10:00:00+00:00']);
    $foreign = doctorAlertPayloads($otherOperator)[0];
    expect($foreign)->toMatchArray(['tenant_id' => $otherTenantId, 'check' => 'webhook_deliveries', 'detail' => '1 stale', 'first_red_at' => '2026-09-07T11:00:00+00:00'])
        ->and(json_encode($foreign))->not->toContain('2 stale');
    expect(ConnectorDoctorAlert::query()->forTenant($tenantId)->count())->toBe(1)
        ->and(ConnectorDoctorAlert::query()->forTenant($otherTenantId)->count())->toBe(1);
});

test('a null alert channel exits zero, sends nothing, and says alerts are disabled', function (): void {
    config()->set('people-connector.doctor.alert_channel', null);
    [$tenantId, $operator] = doctorAlertTenant('Doctor Alert Disabled Tenant');

    doctorAlertRun($tenantId, $operator, '2026-09-07 10:00:00');
    expect(doctorAlertRun($tenantId, $operator, '2026-09-07 11:00:00'))->toBe(0)
        ->and(Artisan::output())->toContain('alerts are disabled');
    Notification::assertNothingSent();
    expect(ConnectorDoctorAlert::query()->count())->toBe(0);
});
