<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;

/** Evidence that the connector authorization gate completed for one port call. */
final readonly class ProviderPortAuthorization
{
    private function __construct(
        public string $providerId,
        public int $tenantId,
        public string $scopeKey,
        public string $permission,
    ) {}

    public static function authorize(
        AuthorizationService $authorization,
        TenantContext $tenantContext,
        ProviderConnectionStore $connections,
        Actor $actor,
        ProviderAdapter $provider,
        ProviderScope $scope,
        string $permission,
    ): self {
        $tenantId = $tenantContext->requireTenantId();
        $descriptor = $provider->descriptor();

        if ($actor->validate() !== null
            || $actor->tenantId !== $tenantId
            || ($scope->companyId !== null && $actor->companyId !== $scope->companyId)) {
            throw new ProviderAuthorizationException(
                providerId: $descriptor->id,
                operation: 'authorize_provider_access',
                message: 'Provider access requires an actor and scope inside the current tenant and company boundary.',
            );
        }

        $active = $connections->active($scope);
        if ($active === null || (string) $active->provider_id !== $descriptor->id
            || (string) $active->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new ProviderAuthorizationException(
                providerId: $descriptor->id,
                operation: 'authorize_provider_access',
                message: 'Provider access requires the adapter selected for the requested scope.',
            );
        }

        $authorization->authorize(
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

        return new self($descriptor->id, $tenantId, $scope->key(), $permission);
    }

    /** Conformance probes are test-only and never create production evidence. */
    public static function forConformance(string $providerId): self
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Conformance authorization is available only in tests.');
        }

        return new self($providerId, 0, 'conformance', 'conformance');
    }

    public function permits(string $providerId): bool
    {
        return $this->providerId === $providerId;
    }
}
