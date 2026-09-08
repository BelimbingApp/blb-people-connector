<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What connector:operator:whoami answers (#235): the acting operator, the
 * tenant and company they act in, and every connector capability with the
 * authorization service's own decision on it.
 *
 * @phpstan-type CapabilityRow array{capability: string, allowed: bool, reason: string}
 */
final readonly class OperatorIdentity
{
    /** @param list<CapabilityRow> $capabilities */
    public function __construct(
        public int $userId,
        public string $actorType,
        public int $tenantId,
        public ?int $companyId,
        public array $capabilities,
    ) {}

    /** @return array{user: int, actor_type: string, tenant: int, company: ?int, capabilities: list<CapabilityRow>} */
    public function toArray(): array
    {
        return ['user' => $this->userId, 'actor_type' => $this->actorType, 'tenant' => $this->tenantId, 'company' => $this->companyId, 'capabilities' => $this->capabilities];
    }
}
