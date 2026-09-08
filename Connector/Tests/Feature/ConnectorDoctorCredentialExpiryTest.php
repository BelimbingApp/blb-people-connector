<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Models\ConnectorDoctorAlert;
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

/**
 * 1012-t (#296): connector:doctor reports provider credential expiry per
 * active connection. The clock is pinned so every window assertion is a fixed
 * distance from a fixed expiry. Self-contained: helpers are prefixed credExp.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 00:00:00');
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

/** @return array{tenantId: int, companyId: int, operator: User, connection: ProviderConnection} */
/**
 * A completed checkpoint, so this connection's workforce_freshness row (#284)
 * is green and only the expiry row under test moves a count or an exit code.
 */
function credExpCheckpoint(int $connectionId): void
{
    $asOf = new DateTimeImmutable;
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $connectionId,
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $asOf, resumeCursor: 'cursor-0', complete: true),
        0,
        $asOf,
    );
}

function credExpTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    if (app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), FirstPartyPeopleAdapter::ID)->id);
    credExpCheckpoint((int) $connection->id);

    return ['tenantId' => $tenantId, 'companyId' => (int) $company->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => $connection];
}

function credExpCredential(int $tenantId, ProviderConnection $connection, string $expiresAt, ?string $revokedAt = null, string $keyId = 'key-a'): ProviderCredentialRecord
{
    return ProviderCredentialRecord::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => (int) $connection->id, 'provider_id' => (string) $connection->provider_id,
        'key_id' => $keyId, 'secret_reference' => 'base-integration:credexp-'.$keyId,
        'audience' => 'provider', 'scopes' => ['workforce:read'],
        'issued_at' => now()->subDay(), 'expires_at' => Carbon::parse($expiresAt),
        'revoked_at' => $revokedAt === null ? null : Carbon::parse($revokedAt),
    ]);
}

/** @return array{check: string, status: string, count: int, detail: string}|null */
function credExpRow(int $tenantId, ProviderConnection $connection): ?array
{
    app(TenantContext::class)->set($tenantId);
    $rows = app(ConnectorDoctor::class)->credentialExpiry($tenantId);

    return array_values(array_filter($rows, static fn (array $row): bool => $row['check'] === 'provider_credential_expiry:'.$connection->id))[0] ?? null;
}

function credExpRun(int $tenantId, User $operator, string $at, array $options = []): int
{
    test()->travelTo($at);

    return Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id] + $options);
}

test('a credential expiring in thirty days is green, yellow after travelling twenty days, red one second after expiry', function (): void {
    $t = credExpTenant('Cred Expiry Window');
    $credential = credExpCredential($t['tenantId'], $t['connection'], '2026-10-08 00:00:00');

    $row = credExpRow($t['tenantId'], $t['connection']);
    expect($row)->toMatchArray(['status' => 'green', 'count' => 0])
        ->and($row['detail'])->toBe("credential {$credential->credential_id} key key-a expires 2026-10-08T00:00:00+00:00");

    // Twenty days on: ten days before expiry, inside the fourteen-day window.
    Carbon::setTestNow('2026-09-28 00:00:00');
    $row = credExpRow($t['tenantId'], $t['connection']);
    expect($row)->toMatchArray(['status' => 'yellow', 'count' => 1])
        ->and($row['detail'])->toContain('inside the 14-day warning window');

    Carbon::setTestNow('2026-10-08 00:00:01');
    expect(credExpRow($t['tenantId'], $t['connection']))->toMatchArray(['status' => 'red', 'count' => 1, 'detail' => 'no usable credential']);
});

test('the warning window is the configured number of days', function (): void {
    $t = credExpTenant('Cred Expiry Config');
    credExpCredential($t['tenantId'], $t['connection'], '2026-10-08 00:00:00');
    Carbon::setTestNow('2026-09-28 00:00:00');

    expect(credExpRow($t['tenantId'], $t['connection'])['status'])->toBe('yellow');

    config()->set('people-connector.doctor.credential_warning_days', 7);
    expect(credExpRow($t['tenantId'], $t['connection'])['status'])->toBe('green');

    Carbon::setTestNow('2026-10-01 00:00:01');
    expect(credExpRow($t['tenantId'], $t['connection']))->toMatchArray(['status' => 'yellow', 'count' => 1]);
});

test('a revoked credential with a later usable replacement is green; revoking the replacement turns it red', function (): void {
    $t = credExpTenant('Cred Expiry Revoked');
    credExpCredential($t['tenantId'], $t['connection'], '2027-01-01 00:00:00', revokedAt: '2026-09-01 00:00:00', keyId: 'key-old');
    $replacement = credExpCredential($t['tenantId'], $t['connection'], '2027-01-01 00:00:00', keyId: 'key-new');

    $row = credExpRow($t['tenantId'], $t['connection']);
    expect($row['status'])->toBe('green')
        ->and($row['detail'])->toContain('key key-new');

    $replacement->forceFill(['revoked_at' => now()])->save();
    expect(credExpRow($t['tenantId'], $t['connection']))->toMatchArray(['status' => 'red', 'count' => 1]);
});

test('an inactive and a retired connection produce no row; two active connections produce two rows and a sibling tenant none', function (): void {
    $t = credExpTenant('Cred Expiry Rows');
    $other = credExpTenant('Cred Expiry Other');
    app(TenantContext::class)->set($t['tenantId']);
    $store = app(ProviderConnectionStore::class);
    $second = $store->activate((int) $store->configure(ProviderScope::tenant(), FirstPartyPeopleAdapter::ID)->id);
    $inactive = $store->configure(ProviderScope::company($t['companyId']), 'test.inactive-expiry');
    $retired = $store->configure(ProviderScope::tenant(), 'test.retired-expiry');
    $retired->forceFill(['status' => ProviderConnection::STATUS_RETIRED])->save();

    app(TenantContext::class)->set($t['tenantId']);
    $checks = array_map(static fn (array $row): string => $row['check'], app(ConnectorDoctor::class)->credentialExpiry($t['tenantId']));

    expect($checks)->toBe(['provider_credential_expiry:'.$t['connection']->id, 'provider_credential_expiry:'.$second->id])
        ->and($checks)->not->toContain('provider_credential_expiry:'.$inactive->id)
        ->and($checks)->not->toContain('provider_credential_expiry:'.$retired->id)
        ->and($checks)->not->toContain('provider_credential_expiry:'.$other['connection']->id);
});

test('--alert sends once on the second consecutive red for the expiry row and a recovery after a credential is issued', function (): void {
    $t = credExpTenant('Cred Expiry Alert');
    $check = 'provider_credential_expiry:'.$t['connection']->id;

    expect(credExpRun($t['tenantId'], $t['operator'], '2026-09-08 10:00:00', ['--record' => true, '--alert' => true]))->toBe(1);
    Notification::assertNothingSent();

    expect(credExpRun($t['tenantId'], $t['operator'], '2026-09-08 11:00:00', ['--record' => true, '--alert' => true]))->toBe(1);
    Notification::assertSentToTimes($t['operator'], ConnectorDoctorAlertNotification::class, 1);
    Notification::assertSentTo($t['operator'], ConnectorDoctorAlertNotification::class, function (ConnectorDoctorAlertNotification $n) use ($t, $check): bool {
        $payload = $n->toArray($t['operator']);

        return $payload['check'] === $check && $payload['kind'] === 'red' && $payload['count'] === 1 && $payload['detail'] === 'no usable credential'
            && $payload['first_red_at'] === '2026-09-08T10:00:00+00:00';
    });

    credExpCredential($t['tenantId'], $t['connection'], '2027-01-01 00:00:00');
    expect(credExpRun($t['tenantId'], $t['operator'], '2026-09-08 12:00:00', ['--record' => true, '--alert' => true]))->toBe(0);
    Notification::assertSentToTimes($t['operator'], ConnectorDoctorAlertNotification::class, 2);
    expect(ConnectorDoctorAlert::query()->forTenant($t['tenantId'])->where('check', $check)->orderBy('id')->pluck('kind')->all())->toBe(['red', 'recovered']);
});

test('--record writes exactly one snapshot per reported check for this tenant and nothing for another', function (): void {
    $t = credExpTenant('Cred Expiry Record');
    $other = credExpTenant('Cred Expiry Record Other');
    app(TenantContext::class)->set($t['tenantId']);
    $expected = count(app(ConnectorDoctor::class)->inspect(Actor::forUser($t['operator']))->checks);

    credExpRun($t['tenantId'], $t['operator'], '2026-09-08 10:00:00', ['--record' => true]);

    $snapshots = DB::table('people_connector_connector_doctor_snapshots');
    expect((clone $snapshots)->where('tenant_id', $t['tenantId'])->count())->toBe($expected)
        ->and((clone $snapshots)->where('tenant_id', $t['tenantId'])->where('check', 'provider_credential_expiry:'.$t['connection']->id)->value('status'))->toBe('red')
        ->and((clone $snapshots)->where('tenant_id', $other['tenantId'])->count())->toBe(0);
});
