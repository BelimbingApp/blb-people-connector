<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\SkillDeactivated;
use App\Domains\PeopleConnector\Skill\Events\SkillDefined;
use App\Domains\PeopleConnector\Skill\Events\SkillReactivated;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Exceptions\SkillCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function skillCatalogEntity(int $tenantId, string $type): WorkforceEntity
{
    return WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
}

/**
 * @return array{int, int, SkillCategory} [tenantId, companyEntityId, category]
 */
function skillCatalogFixture(string $tenantName = 'Catalog Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $company = skillCatalogEntity((int) $tenant->id, 'company');
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');

    return [(int) $tenant->id, (int) $company->id, $category];
}

function skillCatalogDraft(SkillCategory $category, array $overrides = []): SkillDraft
{
    return new SkillDraft(...array_merge([
        'code' => 'forklift.operation',
        'name' => 'Forklift Operation',
        'definition' => 'Operates a counterbalance forklift to the approved standard.',
        'categoryId' => (int) $category->id,
        'scope' => SkillScope::Shared,
        'criticalClassification' => CriticalClassification::Safety,
        'evidenceGuide' => 'Observed lift cycle plus valid licence.',
        'defaultAssessmentMethod' => AssessmentMethod::DirectObservation,
        'defaultReassessmentMonths' => 12,
    ], $overrides));
}

test('a skill carries the workbook parity fields and fires a lifecycle event', function (): void {
    Event::fake([SkillDefined::class]);
    [, $companyEntityId, $category] = skillCatalogFixture();

    $skill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, skillCatalogDraft($category));

    expect($skill->code)->toBe('forklift.operation')
        ->and($skill->scope)->toBe(SkillScope::Shared)
        ->and($skill->isCritical())->toBeTrue()
        ->and($skill->critical_classification)->toBe(CriticalClassification::Safety)
        ->and($skill->default_assessment_method)->toBe(AssessmentMethod::DirectObservation)
        ->and($skill->default_reassessment_months)->toBe(12)
        ->and($skill->active)->toBeTrue()
        ->and($skill->getAuditSubject())->toBe(['name' => 'skill', 'id' => $skill->id]);

    Event::assertDispatched(SkillDefined::class, fn (SkillDefined $event): bool => $event->created && $event->code === 'forklift.operation');
});

test('skill codes are stable: duplicates are refused and revision cannot rename', function (): void {
    [, $companyEntityId, $category] = skillCatalogFixture();
    $store = app(SkillCatalogStore::class);

    $skill = $store->defineSkill($companyEntityId, skillCatalogDraft($category));

    expect(fn () => $store->defineSkill($companyEntityId, skillCatalogDraft($category)))
        ->toThrow(InvalidSkillCatalogException::class, 'already exists');

    expect(fn () => $store->reviseSkill($companyEntityId, (int) $skill->id, skillCatalogDraft($category, ['code' => 'forklift.renamed'])))
        ->toThrow(InvalidSkillCatalogException::class, 'stable');
});

test('validation refuses bad cadence, missing department scope, and inactive categories', function (): void {
    [, $companyEntityId, $category] = skillCatalogFixture();
    $store = app(SkillCatalogStore::class);

    expect(fn () => $store->defineSkill($companyEntityId, skillCatalogDraft($category, [
        'defaultReassessmentMonths' => 0,
    ])))->toThrow(InvalidSkillCatalogException::class, 'positive whole number');

    expect(fn () => $store->defineSkill($companyEntityId, skillCatalogDraft($category, [
        'scope' => SkillScope::Department,
    ])))->toThrow(InvalidSkillCatalogException::class, 'department');

    expect(fn () => $store->defineSkill($companyEntityId, skillCatalogDraft($category, [
        'code' => 'Bad Code!',
    ])))->toThrow(InvalidSkillCatalogException::class, 'code');
});

test('department scope requires an organization-unit entity and owner an employee entity', function (): void {
    [$tenantId, $companyEntityId, $category] = skillCatalogFixture();
    $store = app(SkillCatalogStore::class);

    $department = skillCatalogEntity($tenantId, 'organization_unit');
    $owner = skillCatalogEntity($tenantId, 'employee');

    $skill = $store->defineSkill($companyEntityId, skillCatalogDraft($category, [
        'scope' => SkillScope::Department,
        'departmentEntityId' => (int) $department->id,
        'ownerEmployeeEntityId' => (int) $owner->id,
    ]));

    expect($skill->department_entity_id)->toBe((int) $department->id)
        ->and($skill->owner_employee_entity_id)->toBe((int) $owner->id);

    // Wrong entity type is refused even inside the same tenant.
    expect(fn () => $store->defineSkill($companyEntityId, skillCatalogDraft($category, [
        'code' => 'forklift.other',
        'scope' => SkillScope::Department,
        'departmentEntityId' => (int) $owner->id,
    ])))->toThrow(InvalidSkillCatalogException::class, 'organization_unit');
});

test('tenancy: another tenant cannot see, revise, or reference this catalog', function (): void {
    [$tenantIdA, $companyEntityIdA, $categoryA] = skillCatalogFixture('Tenant A');
    $skillA = app(SkillCatalogStore::class)->defineSkill($companyEntityIdA, skillCatalogDraft($categoryA));

    $tenantB = createTenant(['name' => 'Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    $companyB = skillCatalogEntity((int) $tenantB->id, 'company');
    $store = app(SkillCatalogStore::class);

    expect(Skill::query()->withoutCompanyScope('Counts every row in the tenant on purpose: this asserts cross-tenant isolation, which is a different axis.')->forTenant((int) $tenantB->id)->count())->toBe(0)
        ->and(Skill::query()->withoutCompanyScope('Counts every row in the tenant on purpose: this asserts cross-tenant isolation, which is a different axis.')->forTenant($tenantIdA)->count())->toBe(1);

    // Tenant B cannot revise tenant A's skill…
    expect(fn () => $store->reviseSkill($companyEntityIdA, (int) $skillA->id, skillCatalogDraft($categoryA)))
        ->toThrow(Exception::class);

    // …cannot hang a skill on tenant A's category…
    expect(fn () => $store->defineSkill((int) $companyB->id, skillCatalogDraft($categoryA)))
        ->toThrow(InvalidSkillCatalogException::class, 'same company');

    // …and cannot reference tenant A's company entity at all.
    expect(fn () => $store->defineCategory($companyEntityIdA, 'quality', 'Quality'))
        ->toThrow(InvalidSkillCatalogException::class, 'company');
});

test('deactivation keeps history and category deactivation refuses while skills are active', function (): void {
    Event::fake([SkillDeactivated::class]);
    [, $companyEntityId, $category] = skillCatalogFixture();
    $store = app(SkillCatalogStore::class);

    $skill = $store->defineSkill($companyEntityId, skillCatalogDraft($category));

    expect(fn () => $store->deactivateCategory($companyEntityId, (int) $category->id))
        ->toThrow(InvalidSkillCatalogException::class, 'active skills');

    $store->deactivateSkill($companyEntityId, (int) $skill->id);

    expect($skill->refresh()->active)->toBeFalse()
        ->and(Skill::query()->forCompany((int) app(TenantContext::class)->requireTenantId(), $companyEntityId)->count())->toBe(1);
    Event::assertDispatched(SkillDeactivated::class);

    $store->deactivateCategory($companyEntityId, (int) $category->id);
    expect($category->refresh()->active)->toBeFalse();

    // A skill cannot reactivate under an inactive category.
    expect(fn () => $store->reactivateSkill($companyEntityId, (int) $skill->id))
        ->toThrow(InvalidSkillCatalogException::class, 'category');
});

test('company axis: a sibling company in the same tenant cannot address this catalog', function (): void {
    [$tenantId, $companyEntityIdA, $categoryA] = skillCatalogFixture('Company Axis Tenant');
    $store = app(SkillCatalogStore::class);
    $skillA = $store->defineSkill($companyEntityIdA, skillCatalogDraft($categoryA));

    $companyB = skillCatalogEntity($tenantId, 'company');

    expect(fn () => $store->reviseSkill((int) $companyB->id, (int) $skillA->id, skillCatalogDraft($categoryA)))
        ->toThrow(SkillCatalogRecordNotFoundException::class);
    expect(fn () => $store->deactivateSkill((int) $companyB->id, (int) $skillA->id))
        ->toThrow(SkillCatalogRecordNotFoundException::class);
    expect(fn () => $store->deactivateCategory((int) $companyB->id, (int) $categoryA->id))
        ->toThrow(SkillCatalogRecordNotFoundException::class);
    expect(fn () => $store->defineSkill((int) $companyB->id, skillCatalogDraft($categoryA)))
        ->toThrow(InvalidSkillCatalogException::class, 'same company');

    expect($skillA->refresh()->name)->toBe('Forklift Operation')
        ->and($skillA->active)->toBeTrue();
});

test('a pinned update cannot move a catalog row to a sibling company at the model or database layer', function (): void {
    [$tenantId, $companyEntityId, $category] = skillCatalogFixture('Owner Guard Tenant');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, skillCatalogDraft($category));
    $sibling = (int) skillCatalogEntity($tenantId, 'company')->id;

    // The scope guard is satisfied: both axes are pinned, on the base table.
    // The composite foreign key is satisfied too: the sibling is a real entity
    // in the same tenant. Only the value check stands between the row and a
    // silent move.
    foreach ([
        fn () => Skill::query()->forCompany($tenantId, $companyEntityId)->update(['company_entity_id' => $sibling]),
        fn () => SkillCategory::query()->forCompany($tenantId, $companyEntityId)->update(['company_entity_id' => $sibling]),
        fn () => $skill->fill(['company_entity_id' => $sibling])->save(),
        fn () => $skill->forceFill(['company_entity_id' => $sibling])->save(),
    ] as $move) {
        expect($move)->toThrow(CompanyMoveRefusedException::class, 'would leave its company');
    }

    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    foreach ([Skill::class, SkillCategory::class] as $model) {
        expect(fn () => DB::transaction(fn () => $model::query()
            ->movingCompany('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
            ->forCompany($tenantId, $companyEntityId)
            ->update(['company_entity_id' => $sibling])))
            ->toThrow(QueryException::class, 'cannot move to another company');
    }

    expect((int) $skill->refresh()->company_entity_id)->toBe($companyEntityId)
        ->and((int) $category->refresh()->company_entity_id)->toBe($companyEntityId);
});

test('skill codes are immutable at the model and database layers, not only in the store', function (): void {
    [, $companyEntityId, $category] = skillCatalogFixture('Code Guard Tenant');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, skillCatalogDraft($category));

    expect(fn () => $skill->update(['code' => 'renamed.by.mass.assignment']))
        ->toThrow(InvalidSkillCatalogException::class, 'stable');

    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => Skill::query()->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')->whereKey($skill->id)->update(['code' => 'renamed.by.builder'])))
        ->toThrow(QueryException::class);

    expect($skill->refresh()->code)->toBe('forklift.operation');
});

test('categories can be edited and reactivated, releasing their skills', function (): void {
    Event::fake([SkillReactivated::class]);
    [, $companyEntityId, $category] = skillCatalogFixture('Category Lifecycle Tenant');
    $store = app(SkillCatalogStore::class);
    $skill = $store->defineSkill($companyEntityId, skillCatalogDraft($category));

    $store->editCategory($companyEntityId, (int) $category->id, 'Workplace Safety', 'Renamed by HR.');
    expect($category->refresh()->name)->toBe('Workplace Safety')
        ->and($category->code)->toBe('safety');

    $store->deactivateSkill($companyEntityId, (int) $skill->id);
    $store->deactivateCategory($companyEntityId, (int) $category->id);

    // The door swings back: category first, then its skills.
    $store->reactivateCategory($companyEntityId, (int) $category->id);
    $store->reactivateSkill($companyEntityId, (int) $skill->id);

    expect($category->refresh()->active)->toBeTrue()
        ->and($skill->refresh()->active)->toBeTrue();
    Event::assertDispatched(SkillReactivated::class);
});
