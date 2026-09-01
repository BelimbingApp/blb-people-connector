<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * One skill per company, so a query that leaks past the axis returns a row
 * that visibly belongs to the other company.
 *
 * @return array{Skill, Skill} [alpha, beta]
 */
function companyIsolationRivalSkills(TwoCompanyTenant $fixture): array
{
    $catalog = app(SkillCatalogStore::class);

    $alphaCategory = $catalog->defineCategory($fixture->alphaCompanyEntityId, 'safety', 'Alpha Safety');
    $betaCategory = $catalog->defineCategory($fixture->betaCompanyEntityId, 'process', 'Beta Process');

    $draft = fn (int $categoryId, string $code, string $name): SkillDraft => new SkillDraft(
        code: $code,
        name: $name,
        definition: 'Operates the line to the approved standard.',
        categoryId: $categoryId,
        scope: SkillScope::Shared,
        criticalClassification: null,
        evidenceGuide: null,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    );

    return [
        $catalog->defineSkill($fixture->alphaCompanyEntityId, $draft((int) $alphaCategory->id, 'alpha.public', 'Alpha Public')),
        $catalog->defineSkill($fixture->betaCompanyEntityId, $draft((int) $betaCategory->id, 'beta.secret.process', 'Beta Secret Process')),
    ];
}

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

test('nothing opens the guard without stating a reason', function (): void {
    // The document calls `grep -rn withoutCompanyScope` a complete list of the
    // places the company boundary is deliberately not applied. Laravel's own
    // withoutGlobalScope() opens the same guard and appears in no such grep,
    // so the completeness claim is enforced here rather than trusted.
    expect(CompanyIsolationContract::unreasonedGuardBypasses())->toBe([]);
});

test('a predicate on a joined table cannot pin the base table', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    [$alphaSkill, $betaSkill] = companyIsolationRivalSkills($fixture);
    $skills = (new Skill)->getTable();
    $categories = (new SkillCategory)->getTable();

    // Reproduced against the first version of this guard: the ON clause
    // correlates only on tenant_id, and the company predicate sits on the
    // joined table. Alpha read Beta's skill through it, renamed it, deleted
    // it, and every step reported as guard-compliant.
    $bypass = fn () => Skill::query()
        ->join($categories.' as c', fn ($join) => $join->on('c.tenant_id', '=', $skills.'.tenant_id'))
        ->where($skills.'.tenant_id', $fixture->tenantId)
        ->where('c.company_entity_id', $fixture->alphaCompanyEntityId)
        ->select($skills.'.*');

    expect(fn () => $bypass()->get())->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $bypass()->update(['name' => 'DEFACED VIA JOIN']))->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $bypass()->delete())->toThrow(MissingCompanyScopeException::class);

    expect($betaSkill->refresh()->name)->toBe('Beta Secret Process');

    // A join pinned on the base table is a legitimate query and still runs.
    expect(Skill::query()
        ->join($categories.' as c', fn ($join) => $join->on('c.id', '=', $skills.'.category_id'))
        ->where($skills.'.tenant_id', $fixture->tenantId)
        ->where($skills.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->pluck($skills.'.name')->all())->toBe([$alphaSkill->name]);
});

test('a qualified pin must name the base table or its alias', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalSkills($fixture);
    $skills = (new Skill)->getTable();

    expect(Skill::query()
        ->where($skills.'.tenant_id', $fixture->tenantId)
        ->where($skills.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->count())->toBe(1);

    expect(Skill::query()
        ->from($skills.' as s')
        ->where('s.tenant_id', $fixture->tenantId)
        ->where('s.company_entity_id', $fixture->alphaCompanyEntityId)
        ->count())->toBe(1);

    expect(fn () => Skill::query()
        ->forTenant($fixture->tenantId)
        ->where('somewhere_else.company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class);
});

test('a subquery or a raw tautology cannot stand in for a company value', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalSkills($fixture);
    $categories = (new SkillCategory)->getTable();

    // Laravel records a whereIn subquery as an ordinary `In` holding a single
    // Expression. The subquery is unbounded, so this must not read as a pin.
    expect(fn () => Skill::query()
        ->forTenant($fixture->tenantId)
        ->whereIn('company_entity_id', DB::table($categories)->select('company_entity_id'))
        ->get())->toThrow(MissingCompanyScopeException::class);

    // `company_entity_id = company_entity_id` matches every row.
    expect(fn () => Skill::query()
        ->forTenant($fixture->tenantId)
        ->where('company_entity_id', DB::raw('company_entity_id'))
        ->get())->toThrow(MissingCompanyScopeException::class);

    // A list of real ids does pin, and deliberately so: the guard proves the
    // column is constrained to named companies. Whether the caller may act for
    // those companies is CompanyAttribution's question, not the guard's.
    expect(Skill::query()
        ->forTenant($fixture->tenantId)
        ->whereIn('company_entity_id', [$fixture->alphaCompanyEntityId, $fixture->betaCompanyEntityId])
        ->count())->toBe(2);
});

test('a join cannot claim the base table name by aliasing', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalSkills($fixture);
    $skills = (new Skill)->getTable();
    $categories = (new SkillCategory)->getTable();

    // No raw SQL at all. Aliasing `from` frees the bare table name, and the
    // join takes it — so `$skills.company_entity_id` is a predicate on the
    // categories table while reading, to an earlier version of the guard, as
    // a pin on the base table. Once `from` is aliased, only the alias counts.
    expect(fn () => Skill::query()
        ->from($skills.' as s')
        ->join($categories.' as '.$skills, fn ($join) => $join->on($skills.'.tenant_id', '=', 's.tenant_id'))
        ->where('s.tenant_id', $fixture->tenantId)
        ->where($skills.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class);
});

test('a derived or raw from refuses rather than narrowing in silence', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalSkills($fixture);
    $skills = (new Skill)->getTable();

    // fromSub() reads like it is still inside the guarded model. It is not:
    // the guard cannot tell what an unqualified column refers to once the base
    // relation is derived, so it refuses instead of accepting a pin it cannot
    // verify.
    expect(fn () => Skill::query()
        ->fromSub(DB::table($skills)->where('tenant_id', $fixture->tenantId), 's')
        ->where('company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class, 'derived or raw table');

    expect(fn () => Skill::query()
        ->fromRaw($skills)
        ->where('company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class, 'derived or raw table');
});
