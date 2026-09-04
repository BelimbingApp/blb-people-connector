<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Events\TrainingCourseDeactivated;
use App\Domains\PeopleConnector\Training\Events\TrainingCourseDefined;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Exceptions\TrainingCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function trainingCatalogWorkforceEntity(int $tenantId, string $type): WorkforceEntity
{
    return WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
}

/**
 * @return array{int, int, int, int} [tenantId, companyEntityId, skillId, categoryId]
 */
function trainingCatalogFixture(string $tenantName = 'Training Catalog Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $company = trainingCatalogWorkforceEntity((int) $tenant->id, 'company');
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return [(int) $tenant->id, (int) $company->id, (int) $skill->id, (int) $category->id];
}

function trainingCourseDraft(int $skillId, array $overrides = []): TrainingCourseDraft
{
    return new TrainingCourseDraft(...array_merge([
        'code' => 'forklift.induction',
        'title' => 'Forklift Induction',
        'deliveryMode' => DeliveryMode::InternalClassroom,
        'skillIds' => [$skillId],
        'description' => 'Induction course for new forklift operators.',
    ], $overrides));
}

test('a training course carries the workbook fields, maps its skills, and fires a lifecycle event', function (): void {
    Event::fake([TrainingCourseDefined::class]);
    [, $companyEntityId, $skillId] = trainingCatalogFixture();

    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect($course->code)->toBe('forklift.induction')
        ->and($course->title)->toBe('Forklift Induction')
        ->and($course->delivery_mode)->toBe(DeliveryMode::InternalClassroom)
        ->and($course->active)->toBeTrue()
        ->and($course->skillIds())->toBe([$skillId])
        ->and($course->mappedSkills()->pluck('id')->all())->toBe([$skillId])
        ->and($course->getAuditSubject())->toBe(['name' => 'training_course', 'id' => $course->id]);

    Event::assertDispatched(TrainingCourseDefined::class, fn (TrainingCourseDefined $event): bool => $event->created && $event->code === 'forklift.induction');
});

test('course codes are stable: duplicates are refused and revision cannot rename', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId)))
        ->toThrow(InvalidTrainingCatalogException::class, 'already exists');

    expect(fn () => $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, ['code' => 'forklift.renamed'])))
        ->toThrow(InvalidTrainingCatalogException::class, 'stable');
});

test('a course must map to at least one skill, and every mapped skill must belong to the same company', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    [, , $otherSkillId] = trainingCatalogFixture('Other Training Tenant');
    $store = app(TrainingCatalogStore::class);

    app(TenantContext::class)->set($tenantId);

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, ['skillIds' => []])))
        ->toThrow(InvalidTrainingCatalogException::class, 'at least one skill');

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, ['skillIds' => [$skillId, $otherSkillId]])))
        ->toThrow(InvalidTrainingCatalogException::class, 'same company catalog');
});

test('revising a course replaces its skill mapping rather than accumulating it', function (): void {
    [, $companyEntityId, $skillId, $categoryId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $secondSkill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, new SkillDraft(
        code: 'forklift.maintenance',
        name: 'Forklift Maintenance',
        definition: 'Performs routine forklift maintenance checks.',
        categoryId: $categoryId,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));
    $revised = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'skillIds' => [(int) $secondSkill->id],
        'title' => 'Forklift Induction (Revised)',
    ]));

    expect($revised->title)->toBe('Forklift Induction (Revised)')
        ->and($revised->skillIds())->toBe([(int) $secondSkill->id]);
});

test('deactivate and reactivate toggle the course and fire the deactivation event', function (): void {
    Event::fake([TrainingCourseDeactivated::class]);
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    $deactivated = $store->deactivateCourse($companyEntityId, (int) $course->id);
    expect($deactivated->active)->toBeFalse();

    $reactivated = $store->reactivateCourse($companyEntityId, (int) $course->id);
    expect($reactivated->active)->toBeTrue();

    Event::assertDispatched(TrainingCourseDeactivated::class, fn (TrainingCourseDeactivated $event): bool => $event->code === 'forklift.induction');
});

test('a course cannot be reached, revised, or deactivated across a company or tenant boundary', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    [, $otherCompanyEntityId] = trainingCatalogFixture('Cross-Company Training Tenant');
    $store = app(TrainingCatalogStore::class);

    app(TenantContext::class)->set($tenantId);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => $store->reviseCourse($otherCompanyEntityId, (int) $course->id, trainingCourseDraft($skillId)))
        ->toThrow(TrainingCatalogRecordNotFoundException::class)
        ->and(fn () => $store->deactivateCourse($otherCompanyEntityId, (int) $course->id))
        ->toThrow(TrainingCatalogRecordNotFoundException::class);
});

test('an internal trainer must be a real employee workforce entity in the same tenant', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();

    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => 999_999,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');

    $trainer = trainingCatalogWorkforceEntity($tenantId, 'employee');
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => (int) $trainer->id,
    ]));

    expect($course->internal_trainer_employee_entity_id)->toBe((int) $trainer->id);
});

test('a training course table row participates in company isolation like the skill tables it references', function (): void {
    expect(is_a(TrainingCourse::class, ReferencesWorkforceEntities::class, true))->toBeTrue();

    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => TrainingCourse::query()->where('id', $course->id)->get())
        ->toThrow(MissingCompanyScopeException::class);
});
