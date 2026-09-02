<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\PrivilegedSupportAction;
use App\Domains\PeopleConnector\Connector\Models\PrivilegedSupportGrant;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class PrivilegedSupportService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param list<string> $capabilities */
    public function issue(
        Actor $requester,
        Actor $approver,
        ProviderScope $scope,
        array $capabilities,
        string $purpose,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): PrivilegedSupportGrant {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertActor($requester, $tenantId, $scope);
        $this->assertActor($approver, $tenantId, $scope);

        if ($requester->id === $approver->id || trim($purpose) === '' || $expiresAt <= $issuedAt
            || $expiresAt->getTimestamp() - $issuedAt->getTimestamp() > 3600
            || $capabilities === []
            || array_filter($capabilities, static fn (string $capability): bool => preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $capability) !== 1) !== []) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'issue_break_glass',
                message: 'Break-glass support requires a separate approver, purpose, capability, and one-hour maximum duration.',
            );
        }

        $resource = new ResourceContext(
            type: 'people-connector.support',
            id: $scope->key(),
            companyId: $scope->companyId,
            tenantId: $tenantId,
        );
        $this->authorization->authorize($requester, 'people-connector.support.break-glass', $resource);
        $this->authorization->authorize($approver, 'people-connector.support.break-glass', $resource);

        return DB::transaction(function () use ($tenantId, $scope, $requester, $approver, $capabilities, $purpose, $issuedAt, $expiresAt): PrivilegedSupportGrant {
            $grant = PrivilegedSupportGrant::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $scope->companyId,
                'requested_by_user_id' => $requester->id,
                'approved_by_user_id' => $approver->id,
                'purpose' => trim($purpose),
                'capabilities' => array_values(array_unique($capabilities)),
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ]);

            PrivilegedSupportAction::query()->create([
                'tenant_id' => $tenantId,
                'grant_id' => $grant->id,
                'actor_user_id' => $approver->id,
                'action' => 'grant_issued',
                'outcome' => 'approved',
                'context' => ['purpose' => trim($purpose), 'capabilities' => $grant->capabilities],
                'occurred_at' => $issuedAt,
            ]);

            return $grant;
        });
    }

    /** @param array<string, scalar|null> $context */
    public function recordAction(
        PrivilegedSupportGrant $grant,
        Actor $actor,
        string $capability,
        string $action,
        string $outcome,
        array $context = [],
        ?DateTimeImmutable $occurredAt = null,
    ): PrivilegedSupportAction {
        $tenantId = $this->tenantContext->requireTenantId();
        $occurredAt ??= new DateTimeImmutable;
        $securityAt = new DateTimeImmutable(now()->toISOString());

        return DB::transaction(function () use ($grant, $actor, $capability, $action, $outcome, $context, $occurredAt, $securityAt, $tenantId): PrivilegedSupportAction {
            $grant = $this->currentGrant($grant, $tenantId, true);
            $scope = $grant->company_id === null ? ProviderScope::tenant() : ProviderScope::company((int) $grant->company_id);
            $this->assertGrantActor($actor, $grant);
            $this->assertActor($actor, $tenantId, $scope);
            $this->authorizeSupport($actor, $grant, $tenantId, $scope);

            if (! in_array($capability, $grant->capabilities ?? [], true)
                || ! $grant->isActive($securityAt) || trim($action) === '' || trim($outcome) === '') {
                throw new ProviderAuthorizationException(
                    providerId: 'connector',
                    operation: 'record_break_glass_action',
                    message: 'Expired or revoked break-glass grants cannot perform or record actions.',
                );
            }

            return PrivilegedSupportAction::query()->create([
                'tenant_id' => $tenantId,
                'grant_id' => $grant->id,
                'actor_user_id' => $actor->id,
                'action' => trim($action),
                'outcome' => trim($outcome),
                'context' => array_merge($context, ['capability' => $capability]),
                'occurred_at' => $occurredAt,
            ]);
        });
    }

    public function revoke(PrivilegedSupportGrant $grant, Actor $actor, ?DateTimeImmutable $revokedAt = null): void
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $revokedAt ??= new DateTimeImmutable;

        DB::transaction(function () use ($grant, $actor, $revokedAt, $tenantId): void {
            $grant = $this->currentGrant($grant, $tenantId, true);
            $scope = $grant->company_id === null ? ProviderScope::tenant() : ProviderScope::company((int) $grant->company_id);
            $this->assertGrantActor($actor, $grant);
            $this->assertActor($actor, $tenantId, $scope);
            $this->authorizeSupport($actor, $grant, $tenantId, $scope);

            if (! $grant->isActive($revokedAt)) {
                return;
            }

            $grant->forceFill(['revoked_at' => $revokedAt])->save();
            PrivilegedSupportAction::query()->create([
                'tenant_id' => $tenantId,
                'grant_id' => $grant->id,
                'actor_user_id' => $actor->id,
                'action' => 'grant_revoked',
                'outcome' => 'revoked',
                'context' => [],
                'occurred_at' => $revokedAt,
            ]);
        });
    }

    private function assertActor(Actor $actor, int $tenantId, ProviderScope $scope): void
    {
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId
            || ($scope->companyId !== null && $actor->companyId !== $scope->companyId)) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'authorize_break_glass',
                message: 'Break-glass access requires actors inside the current tenant and company boundary.',
            );
        }
    }

    private function currentGrant(PrivilegedSupportGrant $grant, int $tenantId, bool $forUpdate = false): PrivilegedSupportGrant
    {
        $query = PrivilegedSupportGrant::query()
            ->forTenant($tenantId)
            ->whereKey($grant->getKey());

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'load_break_glass',
                message: 'The break-glass grant is missing or outside the current tenant.',
            );
    }

    private function assertGrantActor(Actor $actor, PrivilegedSupportGrant $grant): void
    {
        if (! in_array($actor->id, [(int) $grant->requested_by_user_id, (int) $grant->approved_by_user_id], true)) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'authorize_break_glass',
                message: 'Only the named requester or approver may mutate a break-glass grant.',
            );
        }
    }

    private function authorizeSupport(Actor $actor, PrivilegedSupportGrant $grant, int $tenantId, ProviderScope $scope): void
    {
        $this->authorization->authorize(
            $actor,
            'people-connector.support.break-glass',
            new ResourceContext(
                type: 'people-connector.support',
                id: $grant->getKey(),
                companyId: $scope->companyId,
                tenantId: $tenantId,
            ),
        );
    }
}
