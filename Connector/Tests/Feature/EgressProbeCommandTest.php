<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
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
function egressProbeTenant(string $name, string $providerId, string $origin): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(
        ProviderScope::company((int) $company->id),
        $providerId,
        new ProviderConnectionMetadata(ProviderConnectionMode::RemoteHttp, $origin),
    )->id);

    return [
        'tenantId' => (int) $tenant->id,
        'operator' => User::factory()->create(['company_id' => $company->id]),
        'connection' => $connection,
    ];
}

/** @return array{server: resource, port: int, certificate: string} */
function egressTlsListener(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    $certificatePath = tempnam(sys_get_temp_dir(), 'connector-egress-');
    file_put_contents($certificatePath, $certificatePem.$keyPem);
    $context = stream_context_create(['ssl' => ['local_cert' => $certificatePath, 'allow_self_signed' => true]]);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

    expect($server)->toBeResource();
    $address = stream_socket_get_name($server, false);

    return ['server' => $server, 'port' => (int) substr((string) $address, strrpos((string) $address, ':') + 1), 'certificate' => $certificatePath];
}

/** Accept the probe's plain TCP connection, then its TLS connection. */
function serveEgressProbe($server, int $tlsDelaySeconds = 0): int
{
    $processId = pcntl_fork();
    if ($processId === 0) {
        $tcp = stream_socket_accept($server, 10);
        if (is_resource($tcp)) {
            fclose($tcp);
        }
        $tls = stream_socket_accept($server, 10);
        if (is_resource($tls)) {
            if ($tlsDelaySeconds > 0) {
                sleep($tlsDelaySeconds);
            } else {
                stream_socket_enable_crypto($tls, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            }
            fclose($tls);
        }
        fclose($server);
        exit(0);
    }

    expect($processId)->toBeGreaterThan(0);

    return $processId;
}

function egressProbeCall(array $fixture): int
{
    return Artisan::call('connector:probe:egress', [
        '--tenant' => $fixture['tenantId'],
        '--as' => $fixture['operator']->id,
        '--json' => true,
    ]);
}

test('egress probe reports DNS TCP and TLS green, then exits red when the listener closes', function (): void {
    $listener = egressTlsListener();
    $processId = serveEgressProbe($listener['server']);
    fclose($listener['server']);

    $target = egressProbeTenant('Egress Probe Tenant', 'test.egress-target', "https://127.0.0.1:{$listener['port']}");
    egressProbeTenant('Foreign Egress Probe Tenant', 'test.egress-foreign', "https://127.0.0.1:{$listener['port']}");

    expect(egressProbeCall($target))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['connections'], 'connection_id'))->toBe([(int) $target['connection']->id])
        ->and(array_column($report['connections'][0]['outcomes'], 'status'))->toBe(['green', 'green', 'green'])
        ->and(array_column($report['connections'][0]['outcomes'], 'check'))->toBe(['dns', 'tcp', 'tls']);

    pcntl_waitpid($processId, $status);
    expect(egressProbeCall($target))->toBe(1);
    $closed = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($closed['connections'][0]['outcomes'][1])->toMatchArray(['check' => 'tcp', 'status' => 'red']);

    unlink($listener['certificate']);
});

test('egress probe bounds a stalled TLS handshake to five seconds', function (): void {
    $listener = egressTlsListener();
    $processId = serveEgressProbe($listener['server'], tlsDelaySeconds: 8);
    fclose($listener['server']);
    $target = egressProbeTenant('Stalled Egress Probe Tenant', 'test.egress-stalled', "https://127.0.0.1:{$listener['port']}");

    $startedAt = hrtime(true);
    expect(egressProbeCall($target))->toBe(1);
    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
    expect($elapsedSeconds)->toBeLessThan(7)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['connections'][0]['outcomes'][2])
        ->toMatchArray(['check' => 'tls', 'status' => 'red']);

    pcntl_waitpid($processId, $status);
    unlink($listener['certificate']);
});
