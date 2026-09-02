<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

final class ProviderAccessAuthorizer
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly ProviderPortResolver $ports,
        private readonly ProviderConnectionStore $connections,
    ) {}

    /**
     * Authorize the BLB actor before asking the provider to resolve a read port.
     *
     * The provider capability check is deliberately second: an installed adapter
     * never gets a chance to run when the connector-side actor/scope check fails.
     *
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
        $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.read');

        return $this->ports->read($provider, $capability, $contract);
    }

    /**
     * Authorize the BLB actor before asking the provider to resolve a write port.
     *
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
        $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.write');

        return $this->ports->write($provider, $capability, $contract);
    }

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
}
