<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScaleLevel;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Support\Facades\DB;

/**
 * The test that would have caught it. Every isolation test in this repository
 * used to compare two tenants, which cannot see a company leak: both companies
 * sit on the same side of the tenant boundary. These put Alpha and Beta in one
 * tenant and check that neither can read or write the other's catalog.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function companyIsolationTenant(): TwoCompanyTenant
{
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    return $fixture;
}

function companyIsolationSkillDraft(int $categoryId, string $code, string $name): SkillDraft
{
    return new SkillDraft(
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
}

/** @return list<ProficiencyLevelDraft> */
function companyIsolationLevels(): array
{
    return [
        new ProficiencyLevelDraft(0, 'Not trained', 'No demonstrated knowledge.', 'No authority.'),
        new ProficiencyLevelDraft(1, 'Competent', 'Works independently.', 'May work alone.'),
    ];
}

test('one company cannot read another company catalog inside the same tenant', function (): void {
    $fixture = companyIsolationTenant();
    $catalog = app(SkillCatalogStore::class);

    $betaCategory = $catalog->defineCategory($fixture->betaCompanyEntityId, 'process', 'Beta Process');
    $catalog->defineSkill(
        $fixture->betaCompanyEntityId,
        companyIsolationSkillDraft((int) $betaCategory->id, 'beta.secret.process', 'Beta Secret Process'),
    );
    $alphaCategory = $catalog->defineCategory($fixture->alphaCompanyEntityId, 'safety', 'Alpha Safety');

    // Alpha's own catalog holds only Alpha's rows.
    expect(Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->count())->toBe(0)
        ->and(SkillCategory::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->pluck('code')->all())
        ->toBe(['safety'])
        ->and(SkillCategory::query()->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId)->pluck('code')->all())
        ->toBe(['process']);

    // And the query that used to hand Alpha the whole tenant now refuses.
    expect(fn () => Skill::query()->forTenant($fixture->tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'is company-owned');
    expect(fn () => SkillCategory::query()->forTenant($fixture->tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'is company-owned');

    // Alpha cannot hang a skill on Beta's category either.
    expect(fn () => $catalog->defineSkill(
        $fixture->alphaCompanyEntityId,
        companyIsolationSkillDraft((int) $betaCategory->id, 'alpha.borrowed', 'Alpha Borrowed'),
    ))->toThrow(Exception::class);

    expect($alphaCategory->company_entity_id)->toBe($fixture->alphaCompanyEntityId);
});

test('one company cannot rename, deactivate or revise another company skill', function (): void {
    $fixture = companyIsolationTenant();
    $catalog = app(SkillCatalogStore::class);

    $betaCategory = $catalog->defineCategory($fixture->betaCompanyEntityId, 'process', 'Beta Process');
    $betaSkill = $catalog->defineSkill(
        $fixture->betaCompanyEntityId,
        companyIsolationSkillDraft((int) $betaCategory->id, 'beta.secret.process', 'Beta Secret Process'),
    );
    $alphaCategory = $catalog->defineCategory($fixture->alphaCompanyEntityId, 'safety', 'Alpha Safety');

    // The reproduced exploit, one step at a time, with Beta's real row ids.
    expect(fn () => $catalog->reviseSkill(
        $fixture->alphaCompanyEntityId,
        (int) $betaSkill->id,
        companyIsolationSkillDraft((int) $alphaCategory->id, 'beta.secret.process', 'DEFACED BY ALPHA'),
    ))->toThrow(Exception::class, 'not found');

    expect(fn () => $catalog->deactivateSkill($fixture->alphaCompanyEntityId, (int) $betaSkill->id))
        ->toThrow(Exception::class, 'not found');

    expect(fn () => $catalog->editCategory($fixture->alphaCompanyEntityId, (int) $betaCategory->id, 'DEFACED BY ALPHA'))
        ->toThrow(Exception::class, 'not found');

    expect(fn () => $catalog->deactivateCategory($fixture->alphaCompanyEntityId, (int) $betaCategory->id))
        ->toThrow(Exception::class, 'not found');

    expect($betaSkill->refresh()->name)->toBe('Beta Secret Process')
        ->and($betaSkill->active)->toBeTrue()
        ->and($betaCategory->refresh()->name)->toBe('Beta Process')
        ->and($betaCategory->active)->toBeTrue();
});

test('one company cannot publish, retire or discard another company proficiency scale', function (): void {
    $fixture = companyIsolationTenant();
    $scales = app(ProficiencyScaleStore::class);

    $betaScale = $scales->draft($fixture->betaCompanyEntityId, 'standard', 'Beta Standard', companyIsolationLevels());

    expect(fn () => $scales->publish($fixture->alphaCompanyEntityId, (int) $betaScale->id))
        ->toThrow(Exception::class, 'not found');
    expect(fn () => $scales->newDraftFrom($fixture->alphaCompanyEntityId, (int) $betaScale->id))
        ->toThrow(Exception::class, 'not found');
    expect(fn () => $scales->discardDraft($fixture->alphaCompanyEntityId, (int) $betaScale->id))
        ->toThrow(Exception::class, 'not found');
    expect(fn () => $scales->retire($fixture->alphaCompanyEntityId, (int) $betaScale->id))
        ->toThrow(Exception::class, 'not found');

    expect($scales->currentScale($fixture->alphaCompanyEntityId, 'standard'))->toBeNull()
        ->and($betaScale->refresh()->status->value)->toBe('draft')
        ->and(ProficiencyScale::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->count())->toBe(0);

    // A scale's levels inherit its company, so they are reachable only through
    // their own scale id.
    expect(ProficiencyScaleLevel::query()->where('scale_id', $betaScale->id)->count())->toBe(2);
    expect(fn () => ProficiencyScaleLevel::query()->forTenant($fixture->tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'scale_id');
});

test('counting a scale\'s levels does not force the author into the escape hatch', function (): void {
    $fixture = companyIsolationTenant();
    $scales = app(ProficiencyScaleStore::class);

    $scales->draft($fixture->alphaCompanyEntityId, 'standard', 'Alpha Standard', companyIsolationLevels());
    $scales->draft($fixture->betaCompanyEntityId, 'standard', 'Beta Standard', companyIsolationLevels());

    // has/whereHas/withCount/doesntHave correlate to the parent with a
    // column-to-column predicate, which the guard cannot read as a pin. If
    // those threw from a properly pinned parent, an author who just wants a
    // level count would reach for withoutCompanyScope() at the call site —
    // manufacturing the very hole this guard exists to prevent. The escape
    // belongs on the relation, stated once, so the good-faith path works.
    $alphaScales = ProficiencyScale::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->withCount('levels')
        ->get();

    expect($alphaScales)->toHaveCount(1)
        ->and((int) $alphaScales->first()->levels_count)->toBe(2)
        ->and((string) $alphaScales->first()->name)->toBe('Alpha Standard');

    expect(ProficiencyScale::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->whereHas('levels', fn ($query) => $query->where('level', 1))
        ->pluck('name')->all())->toBe(['Alpha Standard']);

    expect(ProficiencyScale::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->doesntHave('levels')
        ->count())->toBe(0);
});

test('an appended orWhere on a relation cannot read or write past the parent', function (): void {
    $fixture = companyIsolationTenant();
    $scales = app(ProficiencyScaleStore::class);

    $alphaScale = $scales->draft($fixture->alphaCompanyEntityId, 'standard', 'Alpha Standard', companyIsolationLevels());
    $betaScale = $scales->draft($fixture->betaCompanyEntityId, 'standard', 'Beta Standard', companyIsolationLevels());

    // With an escape on the relation, the escape covers whatever the caller
    // appends — so an unbracketed orWhere read Beta's level, and the same
    // query with ->update() wrote it. The relation carries no escape now, so
    // the guard's first rule catches the orWhere instead.
    expect(fn () => $alphaScale->levels()->orWhere('level', 1)->get())
        ->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $alphaScale->levels()->orWhere('level', 1)->update(['name' => 'DEFACED VIA RELATION']))
        ->toThrow(MissingCompanyScopeException::class);

    // A withCount closure is the same footgun one level down.
    expect(fn () => ProficiencyScale::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->withCount(['levels' => fn ($query) => $query->orWhereRaw('1 = 1')])
        ->get())->toThrow(MissingCompanyScopeException::class);

    expect(ProficiencyScaleLevel::query()->where('scale_id', $betaScale->id)->pluck('name')->all())
        ->toBe(['Not trained', 'Competent'])
        ->and($alphaScale->levels()->count())->toBe(2);
});

/**
 * Two companies in one tenant, plus a whole second tenant, with one skill each.
 * The appended-orWhere and union attacks both reach across *both* axes, so a
 * fixture that stops at the company boundary would under-report them.
 *
 * @return array{0: TwoCompanyTenant, 1: SkillCategory, 2: Skill, 3: SkillCategory, 4: Skill, 5: Skill}
 */
function companyIsolationCatalogs(): array
{
    $fixture = companyIsolationTenant();
    $catalog = app(SkillCatalogStore::class);

    $alphaCategory = $catalog->defineCategory($fixture->alphaCompanyEntityId, 'safety', 'Alpha Safety');
    $alphaSkill = $catalog->defineSkill(
        $fixture->alphaCompanyEntityId,
        companyIsolationSkillDraft((int) $alphaCategory->id, 'alpha.lockout', 'Alpha Lockout'),
    );

    $betaCategory = $catalog->defineCategory($fixture->betaCompanyEntityId, 'process', 'Beta Process');
    $betaSkill = $catalog->defineSkill(
        $fixture->betaCompanyEntityId,
        companyIsolationSkillDraft((int) $betaCategory->id, 'beta.secret.process', 'Beta Secret Process'),
    );

    // A second tenant entirely. `skills()` was a plain hasMany on category_id
    // and TenantOwnedModel carries no global tenant scope, so the SQL the
    // relation emitted had no tenant_id predicate at all.
    app(TenantContext::class)->clear();
    $other = CompanyIsolationContract::twoCompaniesInOneTenant('Gamma Ltd', 'Delta Ltd');
    app(TenantContext::class)->set($other->tenantId);
    $gammaCategory = $catalog->defineCategory($other->alphaCompanyEntityId, 'safety', 'Gamma Safety');
    $gammaSkill = $catalog->defineSkill(
        $other->alphaCompanyEntityId,
        companyIsolationSkillDraft((int) $gammaCategory->id, 'gamma.secret', 'Gamma Secret'),
    );
    app(TenantContext::class)->clear();
    app(TenantContext::class)->set($fixture->tenantId);

    return [$fixture, $alphaCategory, $alphaSkill, $betaCategory, $betaSkill, $gammaSkill];
}

test('a category hands out no skills builder for a caller to widen', function (): void {
    [$fixture, $alphaCategory, $alphaSkill, , $betaSkill, $gammaSkill] = companyIsolationCatalogs();

    // SkillCategory::skills() carried withoutCompanyScope(), and an escape
    // covers whatever a caller appends. The emitted SQL was
    //   where category_id = ? and category_id is not null or id = ?
    // which pins neither company nor tenant, so this read, updated and deleted
    // both a sibling company's row and another tenant's row. The relation is
    // gone, so the attack no longer type-checks.
    expect(fn () => $alphaCategory->skills())->toThrow(BadMethodCallException::class)
        ->and(method_exists($alphaCategory, 'skills'))->toBeFalse();

    // The same predicate written out by hand is refused by the guard's first
    // rule instead: a top-level orWhere disqualifies the query.
    $attack = fn () => Skill::query()
        ->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)
        ->where('category_id', $alphaCategory->id)
        ->orWhere('id', $betaSkill->id)
        ->orWhere('id', $gammaSkill->id);

    expect(fn () => $attack()->get())->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $attack()->update(['name' => 'DEFACED BY ALPHA']))->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $attack()->delete())->toThrow(MissingCompanyScopeException::class);

    // Nothing was read, renamed or removed on either axis.
    expect(DB::table('people_connector_skill_skills')->where('id', $betaSkill->id)->value('name'))
        ->toBe('Beta Secret Process')
        ->and(DB::table('people_connector_skill_skills')->where('id', $gammaSkill->id)->value('name'))
        ->toBe('Gamma Secret')
        ->and(DB::table('people_connector_skill_skills')->count())->toBe(3)
        ->and($alphaSkill->refresh()->name)->toBe('Alpha Lockout');
});

test('what replaced the relation counts only the category own company skills', function (): void {
    [$fixture, $alphaCategory] = companyIsolationCatalogs();

    expect($alphaCategory->skillCount())->toBe(1)
        ->and($alphaCategory->hasActiveSkills())->toBeTrue();

    // A cross-company link is possible: Model::create() builds its insert
    // without scopes, and skills.category_id is keyed on (category_id,
    // tenant_id) rather than on the company. The store refuses this; the
    // database does not. The old relation constrained category_id alone, so it
    // would have surfaced this row. The replacements pin the company too.
    Skill::query()->create([
        'tenant_id' => $fixture->tenantId,
        'company_entity_id' => $fixture->betaCompanyEntityId,
        'category_id' => $alphaCategory->id,
        'code' => 'beta.smuggled',
        'name' => 'Beta Smuggled',
        'definition' => 'Written past the store on purpose.',
        'scope' => 'shared',
        'default_assessment_method' => 'direct_observation',
        'active' => true,
    ]);

    expect($alphaCategory->skillCount())->toBe(1)
        ->and(Skill::query()->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId)
            ->where('category_id', $alphaCategory->id)->count())->toBe(1);
});

test('a level hands out no scale builder for a caller to widen', function (): void {
    $fixture = companyIsolationTenant();
    $scales = app(ProficiencyScaleStore::class);

    $alphaScale = $scales->draft($fixture->alphaCompanyEntityId, 'standard', 'Alpha Standard', companyIsolationLevels());
    $betaScale = $scales->draft($fixture->betaCompanyEntityId, 'standard', 'Beta Standard', companyIsolationLevels());
    $alphaLevel = ProficiencyScaleLevel::query()->where('scale_id', $alphaScale->id)->firstOrFail();

    // scale() was a belongsTo carrying withoutCompanyScope(), so
    // $level->scale()->orWhere(...) read and wrote the sibling company's
    // scale. The escape is genuinely unavoidable — a level names its scale
    // only by primary key — so it survives, but behind a private method
    // returning a model. There is no builder to append to.
    expect(fn () => $alphaLevel->scale())->toThrow(BadMethodCallException::class)
        ->and((new ReflectionClass(ProficiencyScaleLevel::class))->getMethod('owningScale')->isPrivate())
        ->toBeTrue();

    // And the immutability check that method exists for still works.
    $scales->publish($fixture->alphaCompanyEntityId, (int) $alphaScale->id);
    expect(fn () => ProficiencyScaleLevel::query()
        ->where('scale_id', $alphaScale->id)
        ->where('level', 1)
        ->firstOrFail()
        ->update(['name' => 'Renamed after publication']))
        ->toThrow(PublishedScaleImmutableException::class);

    expect(DB::table('people_connector_skill_proficiency_scales')->where('id', $betaScale->id)->value('name'))
        ->toBe('Beta Standard');
});

test('a skill category cannot be lazily loaded across the company boundary', function (): void {
    [$fixture, $alphaCategory, $alphaSkill] = companyIsolationCatalogs();

    // Removing the escape from Skill::category() has one honest cost: a lazy
    // load no longer satisfies the guard, because a belongsTo constrains the
    // parent primary key and that is not the category company column. It
    // refuses rather than reading whatever the key points at.
    expect(fn () => $alphaSkill->fresh()->category)->toThrow(MissingCompanyScopeException::class);

    // The pinned eager load is what callers use instead, and it costs them
    // nothing because they already know their company.
    $tenantId = $fixture->tenantId;
    $company = $fixture->alphaCompanyEntityId;

    $loaded = Skill::query()
        ->forCompany($tenantId, $company)
        ->with(['category' => fn ($query) => $query->forCompany($tenantId, $company)])
        ->get();

    expect($loaded)->toHaveCount(1)
        ->and($loaded->first()->category?->name)->toBe('Alpha Safety')
        ->and($alphaCategory->refresh()->name)->toBe('Alpha Safety');
});

test('a union cannot smuggle a second select past the guard', function (): void {
    [$fixture, , , , $betaSkill, $gammaSkill] = companyIsolationCatalogs();
    $skills = (new Skill)->getTable();
    $levels = (new ProficiencyScaleLevel)->getTable();

    // The guard read wheres, from and joins, and never unions. A union arm is
    // a separate SELECT, so pinning the base did nothing to it: this returned
    // the sibling company's row AND the other tenant's row, hydrated as Skill,
    // and reported itself compliant. Ordinary Eloquent — no DB:: facade, no
    // raw SQL.
    $pinned = fn () => Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);

    expect(fn () => $pinned()->union(fn ($query) => $query->from($skills))->get())
        ->toThrow(MissingCompanyScopeException::class, 'carries a union')
        ->and(fn () => $pinned()->unionAll(fn ($query) => $query->from($skills))->get())
        ->toThrow(MissingCompanyScopeException::class, 'carries a union')
        ->and(fn () => $pinned()->union(fn ($query) => $query->from($skills))->count())
        ->toThrow(MissingCompanyScopeException::class, 'carries a union')
        ->and(fn () => $pinned()->union(fn ($query) => $query->from($skills))->first())
        ->toThrow(MissingCompanyScopeException::class, 'carries a union');

    // An unpinned base with a union arm, and an arm that is itself a pinned
    // Eloquent builder for the sibling company — refused either way, because
    // the guard cannot vouch for an arm it never inspects.
    expect(fn () => Skill::query()->union(fn ($query) => $query->from($skills))->get())
        ->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => $pinned()->union(
            Skill::query()->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId),
        )->get())->toThrow(MissingCompanyScopeException::class, 'carries a union');

    // Class D is guarded on the same rule.
    expect(fn () => ProficiencyScaleLevel::query()
        ->where('scale_id', 1)
        ->union(fn ($query) => $query->from($levels))
        ->get())->toThrow(MissingCompanyScopeException::class, 'carries a union');

    expect(Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->pluck('code')->all())
        ->toBe(['alpha.lockout'])
        ->and(DB::table('people_connector_skill_skills')->whereIn('id', [$betaSkill->id, $gammaSkill->id])->count())
        ->toBe(2);
});

test('the guidance a Class D author is given does not contradict itself', function (): void {
    $fixture = companyIsolationTenant();

    // The message used to say "Use forCompany($tenantId, $companyEntityId)",
    // and doing exactly that raised a LogicException telling the author not
    // to. It already prints the right answer, [scale_id], in its own first
    // sentence.
    try {
        ProficiencyScaleLevel::query()->forTenant($fixture->tenantId)->get();
        $message = '';
    } catch (MissingCompanyScopeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('[scale_id]')
        ->and($message)->toContain('inherits its company from a parent row')
        ->and($message)->not->toContain('Use forCompany(');

    // A Class C model still gets sent to forCompany(), because there it works.
    try {
        Skill::query()->forTenant($fixture->tenantId)->get();
        $classC = '';
    } catch (MissingCompanyScopeException $e) {
        $classC = $e->getMessage();
    }

    expect($classC)->toContain('Use forCompany($tenantId, $companyEntityId)');
});
