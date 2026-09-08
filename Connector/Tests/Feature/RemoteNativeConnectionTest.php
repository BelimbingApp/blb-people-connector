<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderTemporaryException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\ConnectionHealthChecker;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctor;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * [1010-h] / #310: remote native People placement, allowlisted base URL,
 * connection-scoped credential, honest health, and a refused sync until a
 * remote transport exists.
 *
 * Self-contained: every helper is prefixed remoteNative and lives here. The
 * only outside helper is the platform's createTenantWithCompany().
 */

const REMOTE_NATIVE_PROVIDER = 'test.remote-native';
const REMOTE_NATIVE_HOST = 'people.example.test';
const REMOTE_NATIVE_BASE_URL = 'https://people.example.test/api';

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    // Frozen so the five-minute credential window is deterministic.
    Carbon::setTestNow('2026-09-08T12:00:00+00:00');
    config()->set('people-connector.remote.allowed_hosts', [REMOTE_NATIVE_HOST]);
    config()->set('people-connector.remote.health_path', '/health');
    config()->set('people-connector.supported_contract_major', 1);
});

function remoteNativeAuthz(bool $allow = true): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException('connector', 'remote_native', 'The actor lacks the connector connection capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/**
 * An adapter that counts how many times its health() is asked.
 *
 * The count is the point of criterion 2: a remote connection must never be
 * answered by the co-located adapter, and "the report said Unavailable" would
 * also be true if the probe ran and the adapter ran too.
 */
function remoteNativeAdapter(): ProviderAdapter
{
    return new class implements ProviderAdapter, ResolvesProviderPorts
    {
        public int $healthCalls = 0;

        public int $portResolutions = 0;

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(REMOTE_NATIVE_PROVIDER, 'Remote Native Test Provider', '0.1.0', '1.0.0');
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            $this->healthCalls++;

            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            $this->portResolutions++;

            return new class implements BootstrapsWorkforce
            {
                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    return new WorkforcePage([], new DateTimeImmutable, resumeCursor: 'done', complete: true);
                }
            };
        }
    };
}

/** @return array{tenantId: int, companyId: int, operator: User, actor: Actor, adapter: ProviderAdapter} */
function remoteNativeTenant(string $name): array
{
    remoteNativeAuthz();
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    if (app(ProviderRegistry::class)->find(REMOTE_NATIVE_PROVIDER) === null) {
        app(ProviderRegistry::class)->register(remoteNativeAdapter());
    }
    $operator = User::factory()->create(['company_id' => $company->id]);

    return [
        'tenantId' => (int) $tenant->id,
        'companyId' => (int) $company->id,
        'operator' => $operator,
        'actor' => Actor::forUser($operator),
        'adapter' => app(ProviderRegistry::class)->find(REMOTE_NATIVE_PROVIDER),
    ];
}

/**
 * A usable, connection-scoped credential.
 *
 * Provider credentials expire within five minutes of issuance by contract
 * (ProviderCredential refuses a longer window), so the clock is frozen for the
 * test and the row is written inside that window. A fixture with a decade-long
 * expiry is not a long-lived credential; it is an unusable one, and the probe
 * would report the connection unavailable for the wrong reason.
 */
function remoteNativeCredential(ProviderConnection $connection): ProviderCredentialRecord
{
    $issuedAt = Carbon::getTestNow() ?? Carbon::now();

    return ProviderCredentialRecord::query()->create([
        'tenant_id' => (int) $connection->tenant_id,
        'connection_id' => (int) $connection->id,
        'provider_id' => (string) $connection->provider_id,
        'credential_id' => 'remote-cred-'.$connection->id,
        'key_id' => 'remote-key-'.$connection->id,
        'secret_reference' => 'base-integration:remote-fixture',
        'audience' => 'blb-people-connector',
        'scopes' => ['employee_directory:read'],
        'issued_at' => $issuedAt->copy()->subSeconds(30),
        'expires_at' => $issuedAt->copy()->addMinutes(4),
    ]);
}

/** An active remote_http connection with its own usable credential. */
function remoteNativeConnection(array $f): ProviderConnection
{
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company($f['companyId']), REMOTE_NATIVE_PROVIDER);
    $credential = remoteNativeCredential($connection);
    $connection = $store->configure(
        ProviderScope::company($f['companyId']),
        REMOTE_NATIVE_PROVIDER,
        mode: ProviderConnectionMode::RemoteHttp,
        remoteBaseUrl: REMOTE_NATIVE_BASE_URL,
        remoteCredentialId: (int) $credential->id,
    );

    return $store->activate((int) $connection->id);
}

test('a remote_http connection is refused without a base URL, outside the allowlist, or with a sibling connection credential', function (): void {
    $f = remoteNativeTenant('RemoteNativeConfigure');
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company($f['companyId']);
    $store->configure($scope, REMOTE_NATIVE_PROVIDER);

    // A second connection in the same company scope: a credential belonging to
    // it must not be nameable on the first one.
    $sibling = $store->configure(ProviderScope::company($f['companyId']), REMOTE_NATIVE_PROVIDER.'.sibling');
    $siblingCredential = remoteNativeCredential($sibling);

    $connections = ProviderConnection::query()->count();
    // Deliberately unscoped: the assertion is that no refusal wrote a
    // credential row anywhere, which a per-connection count could not show.
    $credentials = ProviderCredentialRecord::query()
        ->withoutCompanyScope('counting every credential row to prove a refusal wrote none')->count();

    // No base URL at all.
    expect(fn () => $store->configure($scope, REMOTE_NATIVE_PROVIDER, mode: ProviderConnectionMode::RemoteHttp))
        ->toThrow(InvalidProviderConfigurationException::class, 'base URL');

    // A host nobody allowlisted.
    expect(fn () => $store->configure($scope, REMOTE_NATIVE_PROVIDER,
        mode: ProviderConnectionMode::RemoteHttp, remoteBaseUrl: 'https://attacker.example/api'))
        ->toThrow(InvalidProviderConfigurationException::class, 'allowed_hosts');

    // The allowlisted host as userinfo, with the real host elsewhere: the
    // allowlist compares the parsed host, so this is the attacker's host.
    expect(fn () => $store->configure($scope, REMOTE_NATIVE_PROVIDER,
        mode: ProviderConnectionMode::RemoteHttp, remoteBaseUrl: 'https://'.REMOTE_NATIVE_HOST.'@attacker.example/api'))
        ->toThrow(InvalidProviderConfigurationException::class, 'allowed_hosts');

    // Another connection's credential.
    expect(fn () => $store->configure($scope, REMOTE_NATIVE_PROVIDER,
        mode: ProviderConnectionMode::RemoteHttp, remoteBaseUrl: REMOTE_NATIVE_BASE_URL, remoteCredentialId: (int) $siblingCredential->id))
        ->toThrow(InvalidProviderConfigurationException::class, 'belong to the connection');

    // Nothing was written by any refusal.
    expect(ProviderConnection::query()->count())->toBe($connections)
        ->and(ProviderCredentialRecord::query()
            ->withoutCompanyScope('counting every credential row to prove a refusal wrote none')->count())->toBe($credentials);
});

test('an unreachable remote host is reported unavailable and the in-process adapter is never asked', function (): void {
    $f = remoteNativeTenant('RemoteNativeUnreachable');
    $connection = remoteNativeConnection($f);
    Http::fake(fn () => throw new ConnectionException('connection refused'));
    $before = $f['adapter']->healthCalls;

    $report = app(ConnectionHealthChecker::class)->check($f['actor']);
    $row = collect($report->rows)->firstWhere('connection', (int) $connection->id);

    expect($row['health'])->toBe(ProviderHealthState::Unavailable->value)
        // The whole point: the co-located adapter reports Healthy by
        // construction, so being asked at all would have produced a lie.
        ->and($f['adapter']->healthCalls)->toBe($before);
});

test('a remote host answering a different contract major is degraded and names both majors', function (): void {
    $f = remoteNativeTenant('RemoteNativeContract');
    $connection = remoteNativeConnection($f);
    Http::fake(['*' => Http::response(['contract_major' => 2], 200)]);

    $health = app(App\Domains\PeopleConnector\Connector\Services\RemoteProviderHealthProbe::class)->probe($connection->refresh());

    expect($health->state)->toBe(ProviderHealthState::Degraded);
    // Both majors, so the operator knows which way the mismatch runs. One
    // needle per assertion: a single toContain with several needles passes
    // unless every one of them is missing.
    expect($health->message)->toContain('remote_2');
    expect($health->message)->toContain('supported_1');
});

test('a healthy remote host is reported healthy and the probe carries the connection credential to the allowlisted host only', function (): void {
    $f = remoteNativeTenant('RemoteNativeHealthy');
    $connection = remoteNativeConnection($f);
    Http::fake(['*' => Http::response(['contract_major' => 1], 200)]);

    $health = app(App\Domains\PeopleConnector\Connector\Services\RemoteProviderHealthProbe::class)->probe($connection->refresh());

    expect($health->state)->toBe(ProviderHealthState::Healthy);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($connection): bool {
        return parse_url($request->url(), PHP_URL_HOST) === REMOTE_NATIVE_HOST
            && $request->hasHeader('Authorization', 'Bearer remote-key-'.$connection->id);
    });
});

test('a sync pass on a remote_http connection is refused before any port resolution and writes no projections or checkpoints', function (): void {
    $f = remoteNativeTenant('RemoteNativeSync');
    $connection = remoteNativeConnection($f);
    $employees = WorkforceEmployeeProjection::query()->forCompany($f['tenantId'], $f['companyId'])->count();
    $checkpoints = SyncCheckpoint::query()->count();
    $issues = ReconciliationIssue::query()->count();
    $resolutionsBefore = $f['adapter']->portResolutions;

    expect(fn () => app(WorkforceSyncRunner::class)->bootstrap(
        app(SchedulerPrincipal::class)->forConnection($connection),
        $f['adapter'],
        (int) $connection->id,
    ))->toThrow(ProviderTemporaryException::class, 'no remote bootstrap transport exists');

    expect(WorkforceEmployeeProjection::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe($employees)
        ->and(SyncCheckpoint::query()->count())->toBe($checkpoints)
        // Refused ahead of port resolution, like the maintenance hold: nothing
        // was read, so nothing could have been written.
        ->and($f['adapter']->portResolutions)->toBe($resolutionsBefore)
        ->and(ReconciliationIssue::query()->count())->toBe($issues + 1);

    $issue = ReconciliationIssue::query()->latest('id')->first();
    expect($issue->issue_key)->toBe(WorkforceSyncRunner::ISSUE_KEY_REMOTE_TRANSPORT_MISSING);
});

test('a sibling tenant remote connection is never probed and never listed', function (): void {
    $away = remoteNativeTenant('RemoteNativeAway');
    remoteNativeConnection($away);

    $here = remoteNativeTenant('RemoteNativeHere');
    Http::fake(['*' => Http::response(['contract_major' => 1], 200)]);

    $report = app(ConnectionHealthChecker::class)->check($here['actor']);

    // The acting tenant has no remote connection of its own, so no request may
    // leave this process at all -- a probe of the other tenant's host would be
    // a cross-tenant read even though it never touched a row.
    Http::assertNothingSent();
    expect(collect($report->rows)->pluck('connection')->all())->toBe([]);
});

test('connector:doctor shows a red remote placement row when the remote host is unreachable', function (): void {
    $f = remoteNativeTenant('RemoteNativeDoctor');
    $connection = remoteNativeConnection($f);
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $report = app(ConnectorDoctor::class)->inspect($f['actor']);
    $row = collect($report->checks)->firstWhere('check', 'remote_placement:'.(int) $connection->id);

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('red');
    expect($row['detail'])->toContain(REMOTE_NATIVE_HOST);
});

test('connector:doctor refuses the remote placement read for an actor outside the tenant', function (): void {
    $f = remoteNativeTenant('RemoteNativeDoctorAuthz');
    remoteNativeConnection($f);
    remoteNativeAuthz(false);

    expect(fn () => app(ConnectorDoctor::class)->inspect($f['actor']))
        ->toThrow(ProviderAuthorizationException::class);
});
