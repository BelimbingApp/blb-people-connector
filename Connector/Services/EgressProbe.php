<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\EgressProbeReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/** Tenant-scoped reachability probe over configured provider origins. */
final class EgressProbe
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly EgressSocketProbe $sockets,
    ) {}

    public function inspect(Actor $actor): EgressProbeReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, ConnectorHealthService::READ_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'probe_egress',
                message: 'An egress probe requires an operator inside the current tenant.',
            );
        }

        $connections = ProviderConnection::query()
            ->forTenant($tenantId)
            ->where('status', ProviderConnection::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->map(fn (ProviderConnection $connection): array => $this->inspectConnection($connection))
            ->all();

        return new EgressProbeReport($connections);
    }

    /**
     * @return array{
     *     connection_id: int,
     *     provider_id: string,
     *     host: ?string,
     *     port: ?int,
     *     outcomes: list<array{check: string, status: string, detail: string}>
     * }
     */
    private function inspectConnection(ProviderConnection $connection): array
    {
        $origin = $connection->public_metadata['endpoint_origin'] ?? null;
        if (! is_string($origin)) {
            return [
                'connection_id' => (int) $connection->id,
                'provider_id' => (string) $connection->provider_id,
                'host' => null,
                'port' => null,
                'outcomes' => array_map(
                    static fn (string $check): array => ['check' => $check, 'status' => 'not_applicable', 'detail' => 'no remote endpoint'],
                    ['dns', 'tcp', 'tls'],
                ),
            ];
        }

        $parts = parse_url($origin);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port = is_array($parts) && is_int($parts['port'] ?? null) ? $parts['port'] : 443;
        $dns = $host !== '' && $this->sockets->resolves($host);
        $tcp = $dns && $this->sockets->connects($host, $port, false);
        $tls = $tcp && $this->sockets->connects($host, $port, true);

        return [
            'connection_id' => (int) $connection->id,
            'provider_id' => (string) $connection->provider_id,
            'host' => $host,
            'port' => $port,
            'outcomes' => [
                $this->outcome('dns', $dns, 'host resolved', 'host did not resolve'),
                $this->outcome('tcp', $tcp, 'connection opened', $dns ? 'connection failed' : 'not attempted after DNS failure'),
                $this->outcome('tls', $tls, 'handshake completed', $tcp ? 'handshake failed or timed out' : 'not attempted after TCP failure'),
            ],
        ];
    }

    /** @return array{check: string, status: string, detail: string} */
    private function outcome(string $check, bool $green, string $success, string $failure): array
    {
        return ['check' => $check, 'status' => $green ? 'green' : 'red', 'detail' => $green ? $success : $failure];
    }
}
