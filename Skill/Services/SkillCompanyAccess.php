<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;

/**
 * Interim answer to "which workforce companies may this actor act for?",
 * pending the ownership contract from blb-people#21 (tracked in
 * blb-people-connector#6).
 *
 * Rule: a workforce company is attributable when its provider connection is
 * company-scoped — then the actor's platform company must match. When the
 * connection is tenant-scoped the attribution is unknown, so we FAIL CLOSED,
 * with one carve-out: a tenant containing exactly one platform company has no
 * cross-company boundary to violate, so its workforce companies stay visible.
 */
class SkillCompanyAccess
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /** @return array<int, string> workforce company entity id => display name */
    public function allowedCompanyEntities(?User $actor): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $actorCompanyId = $actor?->getCompanyId();

        if ($actorCompanyId === null) {
            return [];
        }

        $singleCompanyTenant = Company::query()
            ->where('tenant_id', $tenantId)
            ->count() === 1;

        $connectionCompanies = $this->connectionCompanyIds($tenantId);
        $connectionCompanyByIdentity = ExternalIdentity::query()
            ->forTenant($tenantId)
            ->pluck('connection_id', 'id')
            ->map(fn (int $connectionId): ?int => $connectionCompanies[$connectionId] ?? null);

        return WorkforceCompanyProjection::query()
            ->forTenant($tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(function (WorkforceCompanyProjection $projection) use ($connectionCompanyByIdentity, $actorCompanyId, $singleCompanyTenant): bool {
                $mappedCompanyId = $connectionCompanyByIdentity->get((int) $projection->source_identity_id);

                return $mappedCompanyId === null
                    ? $singleCompanyTenant
                    : (int) $mappedCompanyId === (int) $actorCompanyId;
            })
            ->mapWithKeys(fn (WorkforceCompanyProjection $projection): array => [
                (int) $projection->workforce_entity_id => (string) $projection->name,
            ])
            ->all();
    }

    public function mayActFor(?User $actor, int $companyEntityId): bool
    {
        return array_key_exists($companyEntityId, $this->allowedCompanyEntities($actor));
    }

    /** @return array<int, int|null> connection id => platform company id */
    private function connectionCompanyIds(int $tenantId): array
    {
        return ProviderConnection::query()
            ->forTenant($tenantId)
            ->pluck('company_id', 'id')
            ->map(fn ($companyId): ?int => $companyId === null ? null : (int) $companyId)
            ->all();
    }
}
