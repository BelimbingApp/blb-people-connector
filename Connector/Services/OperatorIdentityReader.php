<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\OperatorIdentity;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;

/**
 * Answers "who am I to the connector" for one operator (#235): tenant,
 * company, and each connector capability as the authorization service
 * decides it, one `can()` per capability, so the answer is the same one
 * every operator command would give. Read-only; it never describes another
 * operator, and an actor outside the current tenant is refused.
 */
final class OperatorIdentityReader
{
    public const CAPABILITY_PREFIX = 'people-connector.';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
    ) {}

    public function read(Actor $actor): OperatorIdentity
    {
        $tenantId = $this->tenants->requireTenantId();
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'whoami', 'An operator identity is read inside the operator\'s own tenant.');
        }

        $rows = [];
        foreach ($this->capabilities() as $capability) {
            // Each row is the service's own decision, not a role lookup: the
            // point is to show what the commands will actually allow.
            $decision = $this->authorization->can($actor, $capability);
            $rows[] = ['capability' => $capability, 'allowed' => $decision->allowed, 'reason' => $decision->reasonCode->value];
        }

        return new OperatorIdentity((int) $actor->id, $actor->type->value, $tenantId, $actor->companyId, $rows);
    }

    /** @return list<string> every declared capability under the connector prefix, sorted */
    private function capabilities(): array
    {
        $declared = config('authz.capabilities', []);
        $capabilities = array_values(array_filter(
            is_array($declared) ? $declared : [],
            static fn (mixed $key): bool => is_string($key) && str_starts_with($key, self::CAPABILITY_PREFIX),
        ));
        sort($capabilities);

        return $capabilities;
    }
}
