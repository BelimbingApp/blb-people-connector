<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use DateTimeImmutable;

/** Evidence that the connector authorization gate completed for one port call. */
final readonly class ProviderPortAuthorization
{
    private function __construct(
        public string $providerId,
        public int $tenantId,
        public string $scopeKey,
        public string $permission,
        public string $capability,
        public string $direction,
        public string $contract,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Every distinct capability gets its own permission name, per the HR data
     * boundary's rule 7.3: a role holds access only through a permission that
     * names the capability. A single 'provider.read'/'provider.write' grant
     * must never stand in for all twelve — that shape was found to let any
     * directory-read role read Payroll or Documents through the same check.
     */
    /**
     * The capability key that gates one direction of one provider port.
     *
     * The direction is the LAST segment because the platform grammar reads
     * domain.resource.action from the end: with it in the middle the action
     * parsed as the port name, every one of these keys was dropped from the
     * registry, and the whole provider surface was denied at runtime.
     *
     * Underscores become dashes rather than being deleted. Deleting them
     * happens to be collision-free across the twelve cases today — I checked —
     * but it throws away the word boundary, so it stays collision-free only by
     * luck as cases are added. A dash keeps the boundary and is what the
     * grammar accepts.
     */
    public static function permissionFor(PeopleCapability $capability, string $direction): string
    {
        $port = str_replace('_', '-', $capability->value);

        return "people-connector.provider.{$port}.{$direction}";
    }

    public static function authorize(
        AuthorizationService $authorization,
        TenantContext $tenantContext,
        ProviderConnectionStore $connections,
        Actor $actor,
        ProviderAdapter $provider,
        ProviderScope $scope,
        PeopleCapability $capability,
        string $contract,
        string $direction,
    ): self {
        $tenantId = $tenantContext->requireTenantId();
        $descriptor = $provider->descriptor();
        $securityNow = new DateTimeImmutable(now()->toISOString());

        if (($direction === 'read' && ! is_a($contract, ReadableProviderPort::class, true))
            || ($direction === 'write' && ! is_a($contract, WritableProviderPort::class, true))
            || ! in_array($direction, ['read', 'write'], true)) {
            throw new ProviderAuthorizationException(
                providerId: $descriptor->id,
                operation: 'authorize_provider_access',
                message: 'Provider port authorization evidence has an invalid direction or contract.',
            );
        }

        $permission = self::permissionFor($capability, $direction);

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

        return new self($descriptor->id, $tenantId, $scope->key(), $permission, $capability->value, $direction, $contract, $securityNow->modify('+60 seconds'));
    }

    /** Conformance probes are test-only and never create production evidence. */
    public static function forConformance(string $providerId): self
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Conformance authorization is available only in tests.');
        }

        return new self($providerId, 0, 'conformance', 'conformance', '*', '*', '*', new DateTimeImmutable('+1 minute'));
    }

    public function permits(
        string $providerId,
        int $tenantId,
        string $scopeKey,
        string $permission,
        string $capability,
        string $direction,
        string $contract,
    ): bool {
        return $this->providerId === $providerId
            && $this->tenantId === $tenantId
            && $this->scopeKey === $scopeKey
            && $this->permission === $permission
            && $this->capability === $capability
            && $this->direction === $direction
            && $this->contract === $contract
            && new DateTimeImmutable(now()->toISOString()) < $this->expiresAt;
    }
}
