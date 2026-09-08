<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\ConnectionHealthCheckReport;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Throwable;

/**
 * Pings every active connection's adapter and reports capability drift
 * against the evidence register (#209, plan 0001 health and compatibility).
 *
 * Drift is the finding that matters: a capability an adapter newly declares
 * without evidence is the thing that would let a port be exercised on an
 * unverified vendor surface. The command exits non-zero on it; this service
 * only reads and compares.
 */
final class ConnectionHealthChecker
{
    public const READ_CAPABILITY = ConnectorHealthService::READ_CAPABILITY;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderRegistry $registry,
    ) {}

    public function check(Actor $actor, ?CapabilityEvidenceRegister $register = null): ConnectionHealthCheckReport
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, self::READ_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'health_check', 'A connection health check reads one tenant and requires an operator inside it.');
        }
        $register ??= CapabilityEvidenceRegister::fromConfig();

        $rows = [];
        $connections = ProviderConnection::query()->forTenant($tenantId)
            ->where('status', ProviderConnection::STATUS_ACTIVE)->orderBy('id')->get();
        foreach ($connections as $connection) {
            $providerId = (string) $connection->provider_id;
            $provider = $this->registry->find($providerId);
            $declared = $provider === null ? [] : array_map(
                static fn (CapabilityDeclaration $declaration): string => $declaration->capability->value,
                $provider->capabilities()->all(),
            );
            $verified = $register->verified($providerId);

            $rows[] = [
                'connection' => (int) $connection->id,
                'provider' => $providerId,
                'registered' => $provider !== null,
                'in_register' => $register->knows($providerId),
                'health' => $provider === null ? ProviderHealthState::Unknown->value : $this->health($provider),
                'declared' => $declared,
                // The comparison: declared without evidence is drift.
                'unsupported_declared' => array_values(array_diff($declared, $verified)),
                'withdrawn' => array_values(array_diff($verified, $declared)),
            ];
        }

        return new ConnectionHealthCheckReport($tenantId, $register->path(), $rows);
    }

    private function health(object $provider): string
    {
        try {
            return $provider->health()->state->value;
        } catch (Throwable) {
            // A health port that throws is an unavailable adapter; the
            // exception text stays out of the report (diagnostic privacy).
            return ProviderHealthState::Unavailable->value;
        }
    }
}
