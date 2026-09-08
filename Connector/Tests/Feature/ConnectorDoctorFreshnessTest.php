<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Notifications\ConnectorDoctorAlertNotification;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctor;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Self-contained (#284): every helper is prefixed doctorFreshness and lives
 * here, so the file passes and fails alone. Connections use the registered
 * first-party adapter (one per scope) so adapter_conformance stays green and
 * the only row that can move is the per-connection workforce_freshness one;
 * a test that needs more connections than scopes names an unregistered
 * provider and does not read the exit code.
 */

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

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @return array{0: int, 1: User, 2: int} tenant id, operator, company id */
function doctorFreshnessTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    if (app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }

    return [(int) $tenant->id, User::factory()->create(['company_id' => $company->id]), (int) $company->id];
}

/** An active connection in the given tenant; first-party and tenant-scoped unless told otherwise. */
function doctorFreshnessConnection(int $tenantId, string $providerId = FirstPartyPeopleAdapter::ID, ?ProviderScope $scope = null): int
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure($scope ?? ProviderScope::tenant(), $providerId);
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    // A usable credential far from expiry, so this connection's
    // provider_credential_expiry row (#296) is green and only the freshness
    // row under test moves a count or an exit code.
    ProviderCredentialRecord::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => $connectionId, 'provider_id' => $providerId,
        'key_id' => 'freshness-key', 'secret_reference' => 'base-integration:freshness-test',
        'audience' => 'provider', 'scopes' => ['workforce:read'],
        'issued_at' => '2020-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00',
    ]);

    return $connectionId;
}

function doctorFreshnessCheckpoint(int $tenantId, int $connectionId, DateTimeImmutable $asOf, int $expectedVersion = 0): void
{
    app(TenantContext::class)->set($tenantId);
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $connectionId,
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $asOf, resumeCursor: 'cursor-'.$expectedVersion, complete: true),
        $expectedVersion,
        $asOf,
    );
}

/** @return array{0: int, 1: Collection<int, array<string, mixed>>} exit code and the JSON rows */
function doctorFreshnessRun(int $tenantId, User $operator, array $options = []): array
{
    $exit = Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--json' => true] + $options);
    $rows = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks']);

    return [$exit, $rows];
}

/** @return Collection<int, array<string, mixed>> */
function doctorFreshnessRows(Collection $rows): Collection
{
    return $rows->filter(fn (array $row): bool => str_starts_with($row['check'], 'workforce_freshness:'))->values();
}

test('an active connection whose checkpoint is older than the max age is a red row with reason exceeded_max_age and the command exits red', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    [$tenantId, $operator] = doctorFreshnessTenant('Doctor Freshness Stale Tenant');
    $connectionId = doctorFreshnessConnection($tenantId);
    doctorFreshnessCheckpoint($tenantId, $connectionId, new DateTimeImmutable('2026-09-04T12:00:00+00:00'));

    [$exit, $rows] = doctorFreshnessRun($tenantId, $operator);

    $row = $rows->firstWhere('check', "workforce_freshness:{$connectionId}");
    expect($exit)->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row['status'])->toBe('red')
        ->and($row['count'])->toBe(1)
        ->and($row['detail'])->toContain('exceeded_max_age', '4320 minutes old');

    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id]))->toBe(1)
        ->and(Artisan::output())->toContain("workforce_freshness:{$connectionId}", 'red', 'exceeded_max_age');
});

test('never synchronized is red never_synchronized and a checkpoint inside the max age is green with its age in minutes', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    [$tenantId, $operator, $companyId] = doctorFreshnessTenant('Doctor Freshness Age Tenant');
    $never = doctorFreshnessConnection($tenantId);
    $fresh = doctorFreshnessConnection($tenantId, scope: ProviderScope::company($companyId));
    doctorFreshnessCheckpoint($tenantId, $fresh, new DateTimeImmutable('2026-09-07T11:15:00+00:00'));

    [$exit, $rows] = doctorFreshnessRun($tenantId, $operator);

    expect($exit)->toBe(1)
        ->and($rows->firstWhere('check', "workforce_freshness:{$never}"))->toBe([
            'check' => "workforce_freshness:{$never}", 'status' => 'red', 'count' => 1, 'detail' => 'never_synchronized',
        ])
        ->and($rows->firstWhere('check', "workforce_freshness:{$fresh}"))->toBe([
            'check' => "workforce_freshness:{$fresh}", 'status' => 'green', 'count' => 0, 'detail' => '45 minutes old, maximum 1440',
        ]);
});

test('inactive and retired connections produce no freshness row', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    [$tenantId, $operator, $companyId] = doctorFreshnessTenant('Doctor Freshness Status Tenant');
    $asOf = new DateTimeImmutable('2026-09-07T11:00:00+00:00');
    // Checkpoints are written while each connection is still active; the
    // status changes come after.
    $inactive = doctorFreshnessConnection($tenantId, 'test.doctor-freshness-inactive');
    doctorFreshnessCheckpoint($tenantId, $inactive, $asOf);
    $retired = doctorFreshnessConnection($tenantId, 'test.doctor-freshness-retired', ProviderScope::company($companyId));
    doctorFreshnessCheckpoint($tenantId, $retired, $asOf);
    ProviderConnection::query()->whereKey($retired)->update(['status' => ProviderConnection::STATUS_RETIRED, 'active_scope_key' => null]);
    // Activating a second connection in the tenant scope deactivates the first.
    $active = doctorFreshnessConnection($tenantId);
    doctorFreshnessCheckpoint($tenantId, $active, $asOf);

    [, $rows] = doctorFreshnessRun($tenantId, $operator);

    expect(ProviderConnection::query()->whereKey($inactive)->value('status'))->toBe(ProviderConnection::STATUS_INACTIVE)
        ->and(doctorFreshnessRows($rows)->pluck('check')->all())->toBe(["workforce_freshness:{$active}"]);
});

test('a sibling tenants stale connection is not listed and an operator of another tenant is refused', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    [$tenantId, $operator] = doctorFreshnessTenant('Doctor Freshness Tenant A');
    [$otherTenantId, $otherOperator] = doctorFreshnessTenant('Doctor Freshness Tenant B');
    $own = doctorFreshnessConnection($tenantId);
    doctorFreshnessCheckpoint($tenantId, $own, new DateTimeImmutable('2026-09-07T11:00:00+00:00'));
    $foreign = doctorFreshnessConnection($otherTenantId);

    [$exit, $rows] = doctorFreshnessRun($tenantId, $operator);
    expect($exit)->toBe(0)
        ->and(doctorFreshnessRows($rows)->pluck('check')->all())->toBe(["workforce_freshness:{$own}"])
        ->and($rows->pluck('check')->all())->not->toContain("workforce_freshness:{$foreign}");

    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $otherOperator->id]))->toBe(1)
        ->and(Artisan::output())->toContain('operator inside the current tenant')
        ->not->toContain('workforce_freshness');
});

test('alert twice on the stale connection sends one notification naming the check and a fresh checkpoint sends one recovery', function (): void {
    [$tenantId, $operator] = doctorFreshnessTenant('Doctor Freshness Alert Tenant');
    $connectionId = doctorFreshnessConnection($tenantId);
    doctorFreshnessCheckpoint($tenantId, $connectionId, new DateTimeImmutable('2026-09-01T09:00:00+00:00'));
    $check = "workforce_freshness:{$connectionId}";
    $run = function (string $at) use ($tenantId, $operator): int {
        Carbon::setTestNow($at);

        return Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--record' => true, '--alert' => true]);
    };

    expect($run('2026-09-07 10:00:00'))->toBe(1);
    Notification::assertNothingSent();

    expect($run('2026-09-07 11:00:00'))->toBe(1);
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 1);

    doctorFreshnessCheckpoint($tenantId, $connectionId, new DateTimeImmutable('2026-09-07T11:30:00+00:00'), 1);
    expect($run('2026-09-07 12:00:00'))->toBe(0);
    Notification::assertSentToTimes($operator, ConnectorDoctorAlertNotification::class, 2);

    $payloads = [];
    Notification::assertSentTo($operator, ConnectorDoctorAlertNotification::class, function (ConnectorDoctorAlertNotification $notification) use (&$payloads, $operator): bool {
        $payloads[] = $notification->toArray($operator);

        return true;
    });
    expect($payloads[0])->toMatchArray(['tenant_id' => $tenantId, 'check' => $check, 'kind' => 'red', 'count' => 1])
        ->and($payloads[0]['detail'])->toContain('exceeded_max_age')
        ->and($payloads[0]['first_red_at'])->toBe('2026-09-07T10:00:00+00:00')
        ->and($payloads[1])->toMatchArray(['check' => $check, 'kind' => 'recovered', 'count' => 0]);
});

test('history lists the latest freshness row per connection', function (): void {
    [$tenantId, $operator, $companyId] = doctorFreshnessTenant('Doctor Freshness History Tenant');
    $first = doctorFreshnessConnection($tenantId);
    $second = doctorFreshnessConnection($tenantId, scope: ProviderScope::company($companyId));
    doctorFreshnessCheckpoint($tenantId, $first, new DateTimeImmutable('2026-09-07T09:00:00+00:00'));

    Carbon::setTestNow('2026-09-07 10:00:00');
    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--record' => true]))->toBe(1);
    doctorFreshnessCheckpoint($tenantId, $second, new DateTimeImmutable('2026-09-07T10:30:00+00:00'));
    Carbon::setTestNow('2026-09-07 11:00:00');
    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--record' => true]))->toBe(0);

    [$exit, $history] = doctorFreshnessRun($tenantId, $operator, ['--history' => 1]);
    $freshness = doctorFreshnessRows($history);

    expect($exit)->toBe(0)
        ->and($freshness)->toHaveCount(2)
        ->and($freshness->firstWhere('check', "workforce_freshness:{$first}"))->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($freshness->firstWhere('check', "workforce_freshness:{$second}"))->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($freshness->pluck('measured_at')->unique()->all())->toBe(['2026-09-07 11:00:00']);
});

test('inspect writes nothing', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    [$tenantId, $operator] = doctorFreshnessTenant('Doctor Freshness Read Only Tenant');
    $connectionId = doctorFreshnessConnection($tenantId);
    $before = DB::table('people_connector_connector_doctor_snapshots')->count();

    $report = app(ConnectorDoctor::class)->inspect(Actor::forUser($operator));

    expect(collect($report->checks)->firstWhere('check', "workforce_freshness:{$connectionId}"))->not->toBeNull()
        ->and(DB::table('people_connector_connector_doctor_snapshots')->count())->toBe($before);
});
