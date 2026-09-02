<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;

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

        $authorization = $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.read', $capability, $contract, 'read');

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->readPortContracts($capability),
            'read',
            $authorization,
            $scope,
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

        $authorization = $this->authorizeActor($actor, $provider, $scope, 'people-connector.provider.write', $capability, $contract, 'write');

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->writePortContracts($capability),
            'write',
            $authorization,
            $scope,
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
        PeopleCapability $capability,
        string $contract,
        string $direction,
    ): ProviderPortAuthorization {
        return ProviderPortAuthorization::authorize(
            $this->authorization,
            $this->tenantContext,
            $this->connections,
            $actor,
            $provider,
            $scope,
            $permission,
            $capability,
            $contract,
            $direction,
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
        ProviderPortAuthorization $authorization,
        ProviderScope $scope,
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

        if (! $provider instanceof ResolvesProviderPorts) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' declares {$contract} but exposes no authorized port resolver.",
                context: $context,
            );
        }

        if (! $authorization->permits(
            $descriptor->id,
            $this->tenantContext->requireTenantId(),
            $scope->key(),
            'people-connector.provider.'.$direction,
            $capability->value,
            $direction,
            $contract,
        )) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' received authorization evidence for another provider.",
                context: $context,
            );
        }

        $port = $provider->resolvePort($contract, $authorization);

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
