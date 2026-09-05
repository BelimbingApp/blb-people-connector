<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\SynchronizedWorkforce;
use App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * One employee projection per company, so a query that leaks past the axis returns a row
 * that visibly belongs to the other company.
 *
 * @return array{WorkforceEmployeeProjection, WorkforceEmployeeProjection} [alpha, beta]
 */
function companyIsolationRivalEmployees(TwoCompanyTenant $fixture): array
{
    $employees = [];

    foreach ([
        [$fixture->alphaCompanyEntityId, 'Alpha Public'],
        [$fixture->betaCompanyEntityId, 'Beta Secret Worker'],
    ] as [$companyEntityId, $name]) {
        $entities = SynchronizedWorkforce::inCompany($fixture->tenantId, $companyEntityId);
        $employee = WorkforceEmployeeProjection::query()
            ->forCompany($fixture->tenantId, $companyEntityId)
            ->where('workforce_entity_id', $entities['employee'])
            ->sole();
        $employee->update([
            'display_name' => $name,
            'organization_entity_id' => $entities['organization_unit'],
        ]);
        $employees[] = $employee->refresh();
    }

    return $employees;
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

test('an array-form where conjunction pins the company axis', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    [$alphaEmployee] = companyIsolationRivalEmployees($fixture);

    // Laravel wraps its convenient array form in a Nested query. The guard
    // must recognise that AND-only group just as it recognises fluent where()
    // calls; otherwise firstOrCreate() and updateOrCreate() fail before they
    // can inspect an already-constrained row.
    expect(WorkforceEmployeeProjection::query()->where([
        'tenant_id' => $fixture->tenantId,
        'company_entity_id' => $fixture->alphaCompanyEntityId,
    ])->sole()->id)->toBe($alphaEmployee->id);

    expect(WorkforceEmployeeProjection::query()->firstOrCreate([
        'tenant_id' => $fixture->tenantId,
        'company_entity_id' => $fixture->alphaCompanyEntityId,
        'display_name' => 'Alpha Public',
    ])->id)->toBe($alphaEmployee->id);

    expect(WorkforceEmployeeProjection::query()->updateOrCreate([
        'tenant_id' => $fixture->tenantId,
        'company_entity_id' => $fixture->alphaCompanyEntityId,
        'display_name' => 'Alpha Public',
    ], [
        'display_name' => 'Alpha Public',
    ])->id)->toBe($alphaEmployee->id);
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

test('company attribution directly rejects an actor whose platform company belongs to another tenant', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign Attribution Tenant']);
    $foreignUser = User::factory()->create(['company_id' => $foreignCompany->id]);
    app(TenantContext::class)->set($fixture->tenantId);
    $attribution = app(CompanyAttribution::class);

    expect($attribution->allowedCompanyEntities($foreignUser))->toBe([])
        ->and($attribution->mayActFor($foreignUser, $fixture->alphaCompanyEntityId))->toBeFalse();
});

test('company attribution follows the workforce entity state, not the retired projection flag', function (): void {
    // #15: deactivate retires the company projection; reactivate restores the
    // entity but leaves the projection retired until the provider restates
    // facts. Authorizing on projection.active made the workforce projections vanish
    // for that gap. Attribution must key off the entity being active.
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $alphaUser = User::factory()->create(['company_id' => $fixture->alphaCompany->id]);
    $attribution = app(CompanyAttribution::class);

    expect($attribution->mayActFor($alphaUser, $fixture->alphaCompanyEntityId))->toBeTrue();

    $entity = WorkforceEntity::query()->forTenant($fixture->tenantId)->findOrFail($fixture->alphaCompanyEntityId);
    $entity->fill([
        'state' => WorkforceEntity::STATE_INACTIVE,
        'deactivated_at' => now(),
    ])->save();
    WorkforceCompanyProjection::query()
        ->withoutCompanyScope('Retires the fixture company projection the way deactivate() does.')
        ->forTenant($fixture->tenantId)
        ->where('workforce_entity_id', $fixture->alphaCompanyEntityId)
        ->update(['active' => false]);

    expect($attribution->mayActFor($alphaUser, $fixture->alphaCompanyEntityId))->toBeFalse()
        ->and(WorkforceCompanyProjection::query()
            ->withoutCompanyScope('Asserts the retired projection still exists for the company entity.')
            ->forTenant($fixture->tenantId)
            ->where('workforce_entity_id', $fixture->alphaCompanyEntityId)
            ->value('active'))->toBeFalse();

    $entity->fill([
        'state' => WorkforceEntity::STATE_ACTIVE,
        'deactivated_at' => null,
    ])->save();

    expect($attribution->mayActFor($alphaUser, $fixture->alphaCompanyEntityId))->toBeTrue()
        ->and(array_keys($attribution->allowedCompanyEntities($alphaUser)))
        ->toBe([$fixture->alphaCompanyEntityId]);
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

    [$alphaEmployee, $betaEmployee] = companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();
    $units = (new WorkforceOrganizationUnitProjection)->getTable();

    // Reproduced against the first version of this guard: the ON clause
    // correlates only on tenant_id, and the company predicate sits on the
    // joined table. Alpha read Beta's employee projection through it, renamed it, deleted
    // it, and every step reported as guard-compliant.
    $bypass = fn () => WorkforceEmployeeProjection::query()
        ->join($units.' as c', fn ($join) => $join->on('c.tenant_id', '=', $employees.'.tenant_id'))
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->where('c.company_entity_id', $fixture->alphaCompanyEntityId)
        ->select($employees.'.*');

    expect(fn () => $bypass()->get())->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $bypass()->update(['display_name' => 'DEFACED VIA JOIN']))->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $bypass()->delete())->toThrow(MissingCompanyScopeException::class);

    // A nested group cannot turn a comparison to a joined table into an
    // outer-query correlation. Array-form wheres are nested too, so this is
    // the boundary that keeps supporting them from reopening the join bypass.
    $nestedBypass = fn () => WorkforceEmployeeProjection::query()
        ->join($units.' as c', fn ($join) => $join->on('c.tenant_id', '=', $employees.'.tenant_id'))
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->where(fn ($query) => $query->whereColumn($employees.'.company_entity_id', '=', 'c.company_entity_id'))
        ->select($employees.'.*');

    expect(fn () => $nestedBypass()->get())->toThrow(MissingCompanyScopeException::class);

    expect($betaEmployee->refresh()->display_name)->toBe('Beta Secret Worker');

    // A join pinned on the base table is a legitimate query and still runs.
    expect(WorkforceEmployeeProjection::query()
        ->join($units.' as c', fn ($join) => $join->on('c.workforce_entity_id', '=', $employees.'.organization_entity_id'))
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->where($employees.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->pluck($employees.'.display_name')->all())->toBe([$alphaEmployee->display_name]);
});

test('a qualified pin must name the base table or its alias', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();

    expect(WorkforceEmployeeProjection::query()
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->where($employees.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->count())->toBe(1);

    expect(WorkforceEmployeeProjection::query()
        ->from($employees.' as s')
        ->where('s.tenant_id', $fixture->tenantId)
        ->where('s.company_entity_id', $fixture->alphaCompanyEntityId)
        ->count())->toBe(1);

    expect(fn () => WorkforceEmployeeProjection::query()
        ->forTenant($fixture->tenantId)
        ->where('somewhere_else.company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class);
});

test('a subquery or a raw tautology cannot stand in for a company value', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalEmployees($fixture);
    $units = (new WorkforceOrganizationUnitProjection)->getTable();

    // Laravel records a whereIn subquery as an ordinary `In` holding a single
    // Expression. The subquery is unbounded, so this must not read as a pin.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->forTenant($fixture->tenantId)
        ->whereIn('company_entity_id', DB::table($units)->select('company_entity_id'))
        ->get())->toThrow(MissingCompanyScopeException::class);

    // `company_entity_id = company_entity_id` matches every row.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->forTenant($fixture->tenantId)
        ->where('company_entity_id', DB::raw('company_entity_id'))
        ->get())->toThrow(MissingCompanyScopeException::class);

    // A list of real ids does pin, and deliberately so: the guard proves the
    // column is constrained to named companies. Whether the caller may act for
    // those companies is CompanyAttribution's question, not the guard's.
    expect(WorkforceEmployeeProjection::query()
        ->forTenant($fixture->tenantId)
        ->whereIn('company_entity_id', [$fixture->alphaCompanyEntityId, $fixture->betaCompanyEntityId])
        ->count())->toBe(2);
});

test('a join cannot claim the base table name by aliasing', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();
    $units = (new WorkforceOrganizationUnitProjection)->getTable();

    // No raw SQL at all. Aliasing `from` frees the bare table name, and the
    // join takes it — so `$employees.company_entity_id` is a predicate on the
    // units table while reading, to an earlier version of the guard, as
    // a pin on the base table. Once `from` is aliased, only the alias counts.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->from($employees.' as s')
        ->join($units.' as '.$employees, fn ($join) => $join->on($employees.'.tenant_id', '=', 's.tenant_id'))
        ->where('s.tenant_id', $fixture->tenantId)
        ->where($employees.'.company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class);
});

test('a derived or raw from refuses rather than narrowing in silence', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();

    // fromSub() reads like it is still inside the guarded model. It is not:
    // the guard cannot tell what an unqualified column refers to once the base
    // relation is derived, so it refuses instead of accepting a pin it cannot
    // verify.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->fromSub(DB::table($employees)->where('tenant_id', $fixture->tenantId), 's')
        ->where('company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class, 'derived or raw table');

    expect(fn () => WorkforceEmployeeProjection::query()
        ->fromRaw($employees)
        ->where('company_entity_id', $fixture->alphaCompanyEntityId)
        ->get())->toThrow(MissingCompanyScopeException::class, 'derived or raw table');
});

test('no join can read as a correlation, however its table is spelled', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();
    $units = (new WorkforceOrganizationUnitProjection)->getTable();

    // The rule this replaced asked whether the correlated side was *absent*
    // from the tables the query can see. Every other rule in the guard is an
    // inclusion test, which fails closed on a name mismatch; that one was an
    // exclusion test, so the identical mismatch failed open. A schema-qualified
    // join put `public.units` in the list while a `whereColumn` against
    // the bare name was not in it, so a join condition read as a correlation:
    // on Postgres it returned both companies' employees, and the same query with
    // ->update() wrote two rows across the boundary.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->join('public.'.$units, fn ($join) => $join->on($units.'.tenant_id', '=', $employees.'.tenant_id'))
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->whereColumn($employees.'.company_entity_id', '=', $units.'.company_entity_id')
        ->get())->toThrow(MissingCompanyScopeException::class);

    // The same mismatch, produced by nothing more exotic than a capital letter.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->join($units.' as C', fn ($join) => $join->on('C.tenant_id', '=', $employees.'.tenant_id'))
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->whereColumn($employees.'.company_entity_id', '=', 'c.company_entity_id')
        ->get())->toThrow(MissingCompanyScopeException::class);
});

test('a company-owned table joined into an unguarded Class T query is not guarded at all', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    [$alphaEmployee, $betaEmployee] = companyIsolationRivalEmployees($fixture);
    $employees = (new WorkforceEmployeeProjection)->getTable();
    $entities = (new WorkforceEntity)->getTable();

    $joinOnOwner = fn ($join) => $join
        ->on($employees.'.company_entity_id', '=', $entities.'.id')
        ->on($employees.'.tenant_id', '=', $entities.'.tenant_id');

    // A. RequireCompanyScope is registered per model by the CompanyOwned
    // trait. WorkforceEntity is Class T and does not use it, so a query
    // built from that base never constructs WorkforceEmployeeProjection's builder: nothing
    // inspects the query and both companies come back.
    //
    // This asserts a LEAK. If it fails, the guard has grown to cover this
    // case -- update the bullet in docs/contracts/company-ownership.md in
    // the same change rather than deleting the test.
    $fromClassT = WorkforceEntity::query()
        ->join($employees, $joinOnOwner)
        ->where($entities.'.tenant_id', $fixture->tenantId)
        ->pluck($employees.'.display_name')->all();

    expect($fromClassT)->toContain($alphaEmployee->display_name)
        ->and($fromClassT)->toContain($betaEmployee->display_name);

    // B. Control. The same join written from the guarded base is refused.
    // Without this, A shows only that a query returning rows can be
    // written -- it would read identically if the guard had been deleted.
    expect(fn () => WorkforceEmployeeProjection::query()
        ->join($entities, $joinOnOwner)
        ->where($employees.'.tenant_id', $fixture->tenantId)
        ->pluck($employees.'.display_name')->all())
        ->toThrow(MissingCompanyScopeException::class);

    // C. Control. The guarded base, properly pinned, returns one company --
    // so the guard is not merely refusing everything.
    expect(WorkforceEmployeeProjection::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->join($entities, $joinOnOwner)
        ->pluck($employees.'.display_name')->all())->toBe([$alphaEmployee->display_name]);
});

test('joinSub is guarded through the Eloquent builder and not through getQuery', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    [$alphaEmployee, $betaEmployee] = companyIsolationRivalEmployees($fixture);
    $entities = (new WorkforceEntity)->getTable();

    // D. parseSub() calls toSql() on the sub-builder. Eloquent\Builder does
    // not define toSql, 'tosql' is in its $passthru list, and __call routes
    // a passthru method through toBase() -- which applies global scopes. So
    // this sub-select IS guarded even though the outer query is Class T.
    // The exception documented in docs/contracts/company-ownership.md, one
    // method call away from E.
    expect(fn () => WorkforceEntity::query()
        ->joinSub(WorkforceEmployeeProjection::query(), 's', fn ($join) => $join->on('s.company_entity_id', '=', $entities.'.id'))
        ->where($entities.'.tenant_id', $fixture->tenantId)
        ->pluck('s.display_name')->all())
        ->toThrow(MissingCompanyScopeException::class);

    // E. ->getQuery() leaves Eloquent, and the scopes with it. Asserts a
    // LEAK, like A: a failure here means the guard improved and the
    // contract needs updating, not that this test is broken.
    $leaked = WorkforceEntity::query()
        ->joinSub(WorkforceEmployeeProjection::query()->getQuery(), 's', fn ($join) => $join->on('s.company_entity_id', '=', $entities.'.id'))
        ->where($entities.'.tenant_id', $fixture->tenantId)
        ->pluck('s.display_name')->all();

    expect($leaked)->toContain($alphaEmployee->display_name)
        ->and($leaked)->toContain($betaEmployee->display_name);
});
