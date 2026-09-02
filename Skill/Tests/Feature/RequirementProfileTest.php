<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfilePublished;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfileRetired;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidRequirementProfileException;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\RequirementProfileNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore;
use App\Domains\PeopleConnector\Skill\Services\RequirementResolver;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function requirementEntity(int $tenantId, string $type): WorkforceEntity
{
    return WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
}

/**
 * @return array{int, int, Skill, Skill}
 */
function requirementFixture(string $tenantName = 'Requirements Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $company = requirementEntity((int) $tenant->id, 'company');
    $catalogStore = app(SkillCatalogStore::class);
    $category = $catalogStore->defineCategory((int) $company->id, 'safety', 'Safety');

    $skillA = $catalogStore->defineSkill((int) $company->id, new \App\Domains\PeopleConnector\Skill\Data\SkillDraft(
        code: 'forklift',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    $skillB = $catalogStore->defineSkill((int) $company->id, new \App\Domains\PeopleConnector\Skill\Data\SkillDraft(
        code: 'packing',
        name: 'Product Packing',
        definition: 'Packs products to specification',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return [(int) $tenant->id, (int) $company->id, $skillA, $skillB];
}

function simpleProfileDraft(Skill $skillA, Skill $skillB): RequirementProfileDraft
{
    return new RequirementProfileDraft(
        code: 'warehouse.operator',
        name: 'Warehouse Operator',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Company),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 60.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 40.0,
            ),
        ],
    );
}

test('a requirement profile carries workbook parity fields and fires lifecycle events', function (): void {
    Event::fake([RequirementProfilePublished::class, RequirementProfileRetired::class]);
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();

    $store = app(RequirementProfileStore::class);
    $profile = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));

    expect($profile->code)->toBe('warehouse.operator')
        ->and($profile->name)->toBe('Warehouse Operator')
        ->and($profile->version)->toBe(1)
        ->and($profile->status)->toBe(RequirementProfileStatus::Draft)
        ->and($profile->items()->count())->toBe(2)
        ->and($profile->selectors()->count())->toBe(1);

    $profile = $store->publish($companyEntityId, (int) $profile->id);

    expect($profile->status)->toBe(RequirementProfileStatus::Published)
        ->and($profile->published_at)->not->toBeNull();

    Event::assertDispatched(RequirementProfilePublished::class);

    $profile = $store->retire($companyEntityId, (int) $profile->id);

    expect($profile->status)->toBe(RequirementProfileStatus::Retired)
        ->and($profile->retired_at)->not->toBeNull();

    Event::assertDispatched(RequirementProfileRetired::class);
});

test('published profiles are immutable and versioning creates new drafts', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $v1 = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));
    $v1 = $store->publish($companyEntityId, (int) $v1->id);

    expect(fn () => $v1->update(['name' => 'Renamed']))
        ->toThrow(PublishedRequirementImmutableException::class, 'immutable');

    expect(fn () => $v1->items()->first()->update(['required_level' => 5]))
        ->toThrow(PublishedRequirementImmutableException::class);

    $v2 = $store->newDraftFrom($companyEntityId, (int) $v1->id);

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe(RequirementProfileStatus::Draft)
        ->and($v2->code)->toBe($v1->code)
        ->and($v2->items()->count())->toBe($v1->items()->count());

    $v2 = $store->publish($companyEntityId, (int) $v2->id);

    expect($v1->refresh()->status)->toBe(RequirementProfileStatus::Retired)
        ->and($v1->retired_at)->not->toBeNull()
        ->and($v2->status)->toBe(RequirementProfileStatus::Published);
});

test('validation enforces weight totaling 100% before publishing', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $draft = new RequirementProfileDraft(
        code: 'bad.weights',
        name: 'Bad Weights',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 30.0,
            ),
        ],
    );

    $profile = $store->draft($companyEntityId, $draft);

    expect(fn () => $store->publish($companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'must total 100%');
});

test('department targeting matches employees by organization unit entity', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $deptA = requirementEntity($tenantId, 'organization_unit');
    $deptB = requirementEntity($tenantId, 'organization_unit');

    $draftA = new RequirementProfileDraft(
        code: 'dept.a.profile',
        name: 'Department A Profile',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $deptA->id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    $profileA = $store->draft($companyEntityId, $draftA);
    $store->publish($companyEntityId, (int) $profileA->id);

    $employeeInDeptA = [
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $deptA->id,
    ];

    $employeeInDeptB = [
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $deptB->id,
    ];

    $resultA = $resolver->resolve($employeeInDeptA);
    expect($resultA['profile'])->not->toBeNull()
        ->and($resultA['profile']->code)->toBe('dept.a.profile')
        ->and($resultA['matched_selectors'])->toContain('department');

    $resultB = $resolver->resolve($employeeInDeptB);
    expect($resultB['profile'])->toBeNull()
        ->and($resultB['explanation'])->toContain('No published requirement profile matches');
});

test('effective dating returns the most recent applicable profile', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $older = new RequirementProfileDraft(
        code: 'dated.profile',
        name: 'Older Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
        effectiveDate: now()->subMonth(),
    );

    $newer = new RequirementProfileDraft(
        code: 'dated.profile',
        name: 'Newer Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
        effectiveDate: now()->subDay(),
    );

    $profileOlder = $store->draft($companyEntityId, $older);
    $store->publish($companyEntityId, (int) $profileOlder->id);

    $profileNewer = $store->draft($companyEntityId, $newer);
    $store->publish($companyEntityId, (int) $profileNewer->id);

    $employee = ['company_entity_id' => $companyEntityId];

    $result = $resolver->resolve($employee);
    expect($result['profile'])->not->toBeNull()
        ->and($result['profile']->version)->toBe(2)
        ->and($result['profile']->name)->toBe('Newer Profile');

    $resultOld = $resolver->resolve($employee, now()->subWeek());
    expect($resultOld['profile'])->not->toBeNull()
        ->and($resultOld['profile']->version)->toBe(1)
        ->and($resultOld['profile']->name)->toBe('Older Profile');
});

test('company isolation: sibling company cannot address profiles', function (): void {
    [$tenantId, $companyAId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $profileA = $store->draft($companyAId, simpleProfileDraft($skillA, $skillB));
    $profileA = $store->publish($companyAId, (int) $profileA->id);

    $companyB = requirementEntity($tenantId, 'company');

    expect(fn () => $store->publish((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);

    expect(fn () => $store->retire((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);

    expect(fn () => $store->discardDraft((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);
});

test('tenant isolation: another tenant cannot see or address profiles', function (): void {
    [$tenantA, $companyAId, $skillA, $skillB] = requirementFixture('Tenant A');
    $store = app(RequirementProfileStore::class);

    $profileA = $store->draft($companyAId, simpleProfileDraft($skillA, $skillB));

    $tenantB = createTenant(['name' => 'Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    $countA = RequirementProfile::query()
        ->withoutCompanyScope('Deliberately counting tenant-wide to assert cross-tenant isolation')
        ->forTenant($tenantA)
        ->count();
    $countB = RequirementProfile::query()
        ->withoutCompanyScope('Deliberately counting tenant-wide to assert cross-tenant isolation')
        ->forTenant((int) $tenantB->id)
        ->count();

    expect($countA)->toBe(1)
        ->and($countB)->toBe(0);
});

test('database immutability guards prevent silent mutation of published profiles', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $profile = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));
    $profile = $store->publish($companyEntityId, (int) $profile->id);

    expect(fn () => DB::transaction(fn () => RequirementProfile::query()
        ->withoutCompanyScope('Deliberately bypasses model layer to test database trigger')
        ->whereKey($profile->id)
        ->update(['name' => 'Renamed by raw query'])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => RequirementItem::query()
        ->withoutCompanyScope('Deliberately bypasses model layer to test database trigger')
        ->where('profile_id', $profile->id)
        ->update(['required_level' => 5])))
        ->toThrow(QueryException::class);

    expect($profile->refresh()->name)->toBe('Warehouse Operator');
});

test('validation refuses duplicate skills, bad sequences, and negative weights', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $duplicateSkills = new RequirementProfileDraft(
        code: 'duplicate',
        name: 'Duplicate Skills',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 50.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $duplicateSkills))
        ->toThrow(InvalidRequirementProfileException::class, 'only once');

    $badSequence = new RequirementProfileDraft(
        code: 'badseq',
        name: 'Bad Sequence',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 5,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 50.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $badSequence))
        ->toThrow(InvalidRequirementProfileException::class, 'contiguous');

    $negativeWeight = new RequirementProfileDraft(
        code: 'negweight',
        name: 'Negative Weight',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: -10.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $negativeWeight))
        ->toThrow(InvalidRequirementProfileException::class, 'non-negative');
});

test('multi-selector profiles require all selectors to match', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $dept = requirementEntity($tenantId, 'organization_unit');

    $multiSelector = new RequirementProfileDraft(
        code: 'multi.selector',
        name: 'Multi Selector Profile',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $dept->id),
            new RequirementSelectorDraft(SelectorType::JobTitle, 'Operator'),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    $profile = $store->draft($companyEntityId, $multiSelector);
    $store->publish($companyEntityId, (int) $profile->id);

    $matchesBoth = $resolver->resolve([
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $dept->id,
        'job_title' => 'Operator',
    ]);

    expect($matchesBoth['profile'])->not->toBeNull()
        ->and($matchesBoth['matched_selectors'])->toHaveCount(2);

    $matchesDeptOnly = $resolver->resolve([
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $dept->id,
        'job_title' => 'Supervisor',
    ]);

    expect($matchesDeptOnly['profile'])->toBeNull()
        ->and($matchesDeptOnly['explanation'])->toContain('Failed on selector');
});

test('criticality enum provides workbook priority multipliers', function (): void {
    expect(RequirementCriticality::Critical->multiplier())->toBe(3)
        ->and(RequirementCriticality::Essential->multiplier())->toBe(2)
        ->and(RequirementCriticality::Development->multiplier())->toBe(1);
});
