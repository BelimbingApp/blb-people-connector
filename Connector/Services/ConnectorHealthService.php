<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\ConnectorConnectionHealth;
use App\Domains\PeopleConnector\Connector\Data\ConnectorHealthReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * The operator's answer to "is this installation actually wired up correctly".
 *
 * It reads and reports. The two things most worth knowing — nothing is
 * registered, or the adapter speaks a contract major this connector does not
 * support — are reported as findings rather than thrown, because refusing the
 * read on exactly those conditions would hide the answer behind the question.
 *
 * There is no route. This is an operator-only read, and giving it a URL is a
 * separate decision with its own exposure to argue about.
 */
final class ConnectorHealthService
{
    public const READ_CAPABILITY = 'people-connector.connection.list';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly ProviderRegistry $registry,
        private readonly WorkforceFreshnessPolicy $freshness,
    ) {}

    public function read(Actor $actor): ConnectorHealthReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::READ_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'read_health',
                message: 'A connector health read describes one tenant and requires an actor inside it.',
            );
        }

        $adapter = $this->registry->active();
        $descriptor = $adapter?->descriptor();
        $supportedMajor = self::supportedContractMajor();

        return new ConnectorHealthReport(
            tenantId: $tenantId,
            configuredAdapterId: $this->registry->configuredProviderId(),
            adapterId: $descriptor?->id,
            adapterName: $descriptor?->name,
            adapterVersion: $descriptor?->adapterVersion,
            contractVersion: $descriptor?->contractVersion,
            contractCompatible: $descriptor !== null && $descriptor->contractMajor() === $supportedMajor,
            supportedContractMajor: $supportedMajor,
            capabilities: $adapter === null ? [] : array_map(
                static fn (CapabilityDeclaration $declaration): string => $declaration->capability->value,
                $adapter->capabilities()->all(),
            ),
            connections: $this->connectionHealth($tenantId),
        );
    }

    /** @return list<ConnectorConnectionHealth> */
    private function connectionHealth(int $tenantId): array
    {
        $connections = ProviderConnection::query()
            ->forTenant($tenantId)
            ->orderBy('id')
            ->get();

        return $connections->map(function (ProviderConnection $connection): ConnectorConnectionHealth {
            $freshness = $this->freshness->for((int) $connection->id);

            return new ConnectorConnectionHealth(
                connectionId: (int) $connection->id,
                scopeKey: (string) $connection->scope_key,
                status: (string) $connection->status,
                stale: $freshness->isStale(),
                staleReason: $freshness->staleReason,
                ageMinutes: $freshness->ageMinutes(),
            );
        })->all();
    }

    private static function supportedContractMajor(): int
    {
        $major = config('people-connector.supported_contract_major', 1);

        if (! is_int($major) || $major < 1) {
            throw new \InvalidArgumentException('people-connector.supported_contract_major must be a positive integer.');
        }

        return $major;
    }
}
