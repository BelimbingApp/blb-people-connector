<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;

/**
 * Answers "which workforce companies may this user act for?".
 *
 * The company axis in docs/contracts/company-ownership.md says which column a
 * query must constrain. This service supplies the value that goes in it, and
 * it is the one place in the repository that decides who may act for whom, so
 * that a future contract has a single method to replace rather than a rule
 * copied into every module.
 *
 * The rule today: a workforce company is attributable when its provider
 * connection is company-scoped, in which case the actor's platform company
 * must match. When the connection is tenant-scoped — one HR install serving a
 * whole tenant, which is a normal deployment — nothing stored anywhere says
 * which platform company a workforce company corresponds to, so this FAILS
 * CLOSED. The single carve-out is a tenant that has only ever held one
 * platform company: there is no cross-company boundary there to violate.
 *
 * Closing the gap properly needs a stored workforce-company → platform-company
 * link. That is a schema and product decision, tracked in
 * BelimbingApp/blb-people#21.
 */
class CompanyAttribution
{
    /** @var array<string, array<int, string>> */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<int, string> workforce company entity id => display name
     */
    public function allowedCompanyEntities(?User $actor): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $actorCompanyId = $actor?->getCompanyId();

        if ($actorCompanyId === null) {
            return [];
        }

        // A page asks this several times — the picker, the guard on every
        // action, and the render pass — and the answer is the same each time.
        // The key carries both the tenant and the actor's company, so a reused
        // instance in a long-running worker cannot serve one actor's answer to
        // another; that is the trap that pinned a tenant per Octane worker in
        // the provider health store.
        return $this->memo[$tenantId.':'.$actorCompanyId] ??= $this->resolve($tenantId, (int) $actorCompanyId);
    }

    public function mayActFor(?User $actor, int $companyEntityId): bool
    {
        return array_key_exists($companyEntityId, $this->allowedCompanyEntities($actor));
    }

    /** @return array<int, string> */
    private function resolve(int $tenantId, int $actorCompanyId): array
    {
        $projections = WorkforceCompanyProjection::query()
            ->withoutCompanyScope('Enumerating which companies exist is what produces the company axis; it cannot itself be scoped to one.')
            ->forTenant($tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get(['workforce_entity_id', 'source_identity_id', 'name']);

        if ($projections->isEmpty()) {
            return [];
        }

        $allowed = [];
        $connectionByIdentity = $this->connectionByIdentity(
            $tenantId,
            $projections->pluck('source_identity_id')->map(intval(...))->unique()->values()->all(),
        );
        $platformCompanyByConnection = $this->platformCompanyByConnection($tenantId);
        $singleCompanyTenant = $this->hasOnlyEverHadOneCompany($tenantId);

        foreach ($projections as $projection) {
            $connectionId = $connectionByIdentity[(int) $projection->source_identity_id] ?? null;
            $platformCompanyId = $connectionId === null
                ? null
                : $platformCompanyByConnection[$connectionId] ?? null;

            $attributable = $platformCompanyId === null
                ? $singleCompanyTenant
                : $platformCompanyId === $actorCompanyId;

            if ($attributable) {
                $allowed[(int) $projection->workforce_entity_id] = (string) $projection->name;
            }
        }

        return $allowed;
    }

    /**
     * Only the identities the company projections actually point at. Loading
     * every external identity in the tenant to find a handful of companies
     * costs the whole workforce to answer a question about its companies.
     *
     * @param  list<int>  $identityIds
     * @return array<int, int>
     */
    private function connectionByIdentity(int $tenantId, array $identityIds): array
    {
        if ($identityIds === []) {
            return [];
        }

        return ExternalIdentity::query()
            ->forTenant($tenantId)
            ->whereIn('id', $identityIds)
            ->where('resource_type', WorkforceResourceType::Company->value)
            ->pluck('connection_id', 'id')
            ->map(intval(...))
            ->all();
    }

    /** @return array<int, int|null> connection id => platform company id */
    private function platformCompanyByConnection(int $tenantId): array
    {
        return ProviderConnection::query()
            ->forTenant($tenantId)
            ->pluck('company_id', 'id')
            ->map(fn ($companyId): ?int => $companyId === null ? null : (int) $companyId)
            ->all();
    }

    /**
     * Counted including soft-deleted companies on purpose. A tenant that once
     * held two companies still has two companies' data in it, and archiving
     * one must not quietly reopen the carve-out and hand the survivor
     * everything the other one owned.
     */
    private function hasOnlyEverHadOneCompany(int $tenantId): bool
    {
        return Company::query()->withTrashed()->where('tenant_id', $tenantId)->count() === 1;
    }
}
