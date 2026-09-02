<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * The only way to reach a provider port.
 *
 * Authorization is not a wrapper around this service; it is the service's
 * first step. Construction requires the authorization dependencies and
 * read()/write() require the acting principal and scope, so a port cannot be
 * resolved without the actor, tenant, company and connection checks having
 * passed. See docs/contracts/company-ownership.md: the guard fails when omitted.
 */
final class ProviderPortResolver
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly ProviderConnectionStore $connections,
    ) {}

    /**
     * @template TPort of ReadableProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @return TPort
     */
    public function read(
        Actor $actor,
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
        ProviderScope $scope,
    ): ReadableProviderPort {
        if (! is_a($contract, ReadableProviderPort::class, true)) {
            throw new \InvalidArgumentException('Readable provider resolution requires a readable port interface.');
        }

        $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.read');

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->readPortContracts($capability),
            'read',
        );
    }

    /**
     * @template TPort of WritableProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @return TPort
     */
    public function write(
        Actor $actor,
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
        ProviderScope $scope,
    ): WritableProviderPort {
        if (! is_a($contract, WritableProviderPort::class, true)) {
            throw new \InvalidArgumentException('Writable provider resolution requires a writable port interface.');
        }

        $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.write');

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->writePortContracts($capability),
            'write',
        );
    }

    /**
     * The provider capability check is deliberately second: an installed adapter
     * never gets a chance to run when the connector-side actor/scope check fails.
     */
    private function authorizeActor(
        Actor $actor,
        ProviderAdapter $provider,
        ProviderScope $scope,
        string $permission,
    ): void {
        $tenantId = $this->tenantContext->requireTenantId();
        $descriptor = $provider->descriptor();

        if (($actor->validate() !== null)
            || $actor->tenantId !== $tenantId
            || ($scope->companyId !== null && $actor->companyId !== $scope->companyId)) {
            throw new ProviderAuthorizationException(
                providerId: $descriptor->id,
                operation: 'authorize_provider_access',
                message: 'Provider access requires an actor and scope inside the current tenant and company boundary.',
                context: [
                    'tenant_id' => $tenantId,
                    'scope' => $scope->key(),
                    'permission' => $permission,
                ],
            );
        }

        $active = $this->connections->active($scope);
        if ($active === null || (string) $active->provider_id !== $descriptor->id
            || (string) $active->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new ProviderAuthorizationException(
                providerId: $descriptor->id,
                operation: 'authorize_provider_access',
                message: 'Provider access requires the adapter selected for the requested scope.',
                context: [
                    'tenant_id' => $tenantId,
                    'scope' => $scope->key(),
                    'permission' => $permission,
                ],
            );
        }

        $this->authorization->authorize(
            $actor,
            $permission,
            new ResourceContext(
                type: 'people-connector.provider',
                id: $descriptor->id,
                companyId: $scope->companyId,
                tenantId: $tenantId,
            ),
            [
                'provider_id' => $descriptor->id,
                'scope' => $scope->key(),
                'permission' => $permission,
            ],
        );
    }

    /**
     * @template TPort of ProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @param  list<class-string>  $declaredContracts
     * @return TPort
     */
    private function resolve(
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
        array $declaredContracts,
        string $direction,
    ): ProviderPort {
        $descriptor = $provider->descriptor();
        $context = [
            'capability' => $capability->value,
            'direction' => $direction,
            'port_contract' => $contract,
        ];

        if (! in_array($contract, $declaredContracts, true)) {
            throw new UnsupportedProviderOperation(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' does not support {$direction} access for capability '{$capability->value}' through {$contract}.",
                context: $context,
            );
        }

        $port = $provider->resolvePort($contract);

        if (! $port instanceof $contract) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' declares {$contract} but cannot resolve a compatible implementation.",
                context: $context,
            );
        }

        return $port;
    }
}
