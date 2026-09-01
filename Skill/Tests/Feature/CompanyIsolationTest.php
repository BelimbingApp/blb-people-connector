<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScaleLevel;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;

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
