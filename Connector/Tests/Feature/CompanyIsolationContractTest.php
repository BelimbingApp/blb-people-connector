<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('the repository actually contains company-owned models to check', function (): void {
    // Guards the guard: a discovery bug that finds nothing would make every
    // dataset-driven test below pass by having no cases at all.
    expect(CompanyIsolationContract::companyOwnedModels())->not->toBeEmpty();
});

test('every company-owned model refuses a query that does not pin the company', function (string $model): void {
    expect(CompanyIsolationContract::violations($model))->toBe([]);
})->with(CompanyIsolationContract::companyOwnedModels());

test('two companies in one tenant are visible to each other only through the company axis', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $alphaProjection = WorkforceCompanyProjection::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->sole();

    expect((string) $alphaProjection->name)->toBe('Alpha Industries')
        ->and(WorkforceCompanyProjection::query()
            ->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId)
            ->sole()->name)->toBe('Beta Works');

    // The line every lane wrote. Both companies share the tenant, so it would
    // have returned both.
    expect(fn () => WorkforceCompanyProjection::query()->forTenant($fixture->tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'is company-owned');
});

test('a user is offered only the workforce company their own platform company connects to', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $alphaUser = User::factory()->create(['company_id' => $fixture->alphaCompany->id]);
    $betaUser = User::factory()->create(['company_id' => $fixture->betaCompany->id]);

    expect(array_keys(app(CompanyAttribution::class)->allowedCompanyEntities($alphaUser)))
        ->toBe([$fixture->alphaCompanyEntityId])
        ->and(array_keys(app(CompanyAttribution::class)->allowedCompanyEntities($betaUser)))
        ->toBe([$fixture->betaCompanyEntityId])
        ->and(app(CompanyAttribution::class)->mayActFor($alphaUser, $fixture->betaCompanyEntityId))->toBeFalse()
        ->and(app(CompanyAttribution::class)->mayActFor(null, $fixture->alphaCompanyEntityId))->toBeFalse();
});

test('archiving a sibling company does not reopen the single-company carve-out', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    // A workforce company whose connection serves the whole tenant cannot be
    // attributed to a platform company, so nobody may act for it…
    $unattributed = CompanyIsolationContract::synchronizedCompany(
        $fixture->tenantId,
        platformCompanyId: null,
        name: 'Tenant-wide Provider Co',
    );
    $alphaUser = User::factory()->create(['company_id' => $fixture->alphaCompany->id]);

    expect(app(CompanyAttribution::class)->mayActFor($alphaUser, $unattributed))->toBeFalse();

    // …and soft-deleting the sibling company must not change that. The count
    // that drives the carve-out includes archived companies, because a tenant
    // that once held two companies still holds two companies' data.
    $fixture->betaCompany->delete();

    expect(Company::query()->where('tenant_id', $fixture->tenantId)->count())->toBe(1)
        ->and(app(CompanyAttribution::class)->mayActFor($alphaUser, $unattributed))->toBeFalse()
        ->and(app(CompanyAttribution::class)->mayActFor($alphaUser, $fixture->betaCompanyEntityId))->toBeFalse();
});
