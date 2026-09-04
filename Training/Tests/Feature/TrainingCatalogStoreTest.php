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
use App\Domains\PeopleConnector\Training\Events\TrainingCourseReactivated;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Exceptions\TrainingCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

test('code stability is enforced at the model layer too, independent of the store', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    // Goes around TrainingCatalogStore entirely — reviseCourse() already
    // refuses a changed code before any model write, so this is the only
    // path that reaches TrainingCourse::booted()'s own guard.
    app(TenantContext::class)->set($tenantId);
    $loaded = TrainingCourse::query()->forCompany($tenantId, $companyEntityId)->findOrFail($course->id);

    expect(fn () => $loaded->update(['code' => 'forklift.renamed']))
        ->toThrow(InvalidTrainingCatalogException::class, 'stable');
});

test('a course must map to at least one skill, and every mapped skill must belong to the same company', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    // Sibling company in the *same* tenant — the company axis, not a second tenant (#92).
    $siblingCompanyId = (int) trainingCatalogWorkforceEntity($tenantId, 'company')->id;
    $siblingCategory = app(SkillCatalogStore::class)->defineCategory($siblingCompanyId, 'safety', 'Safety');
    $siblingSkill = app(SkillCatalogStore::class)->defineSkill($siblingCompanyId, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Sibling-company skill.',
        categoryId: (int) $siblingCategory->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, ['skillIds' => []])))
        ->toThrow(InvalidTrainingCatalogException::class, 'at least one skill');

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'skillIds' => [$skillId, (int) $siblingSkill->id],
    ])))->toThrow(InvalidTrainingCatalogException::class, 'same company catalog');
});

test('blank titles and illegal codes fail closed (#92)', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'title' => '   ',
    ])))->toThrow(InvalidTrainingCatalogException::class, 'title');

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'code' => 'Bad Code!',
    ])))->toThrow(InvalidTrainingCatalogException::class, 'lowercase');
});

test('database rejects a sibling-company skill planted on the course join table (#92)', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    $siblingCompanyId = (int) trainingCatalogWorkforceEntity($tenantId, 'company')->id;
    $siblingCategory = app(SkillCatalogStore::class)->defineCategory($siblingCompanyId, 'ops', 'Ops');
    $siblingSkill = app(SkillCatalogStore::class)->defineSkill($siblingCompanyId, new SkillDraft(
        code: 'sibling.skill',
        name: 'Sibling Skill',
        definition: 'Must not attach across company ownership.',
        categoryId: (int) $siblingCategory->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    // Raw insert must fail closed at the company-owner DB guard (not only via mappedSkills).
    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_course_skills')->insert([
        'tenant_id' => $tenantId,
        'course_id' => $course->id,
        'skill_id' => $siblingSkill->id,
    ])))->toThrow(QueryException::class);

    expect($course->fresh()->skillIds())->toBe([$skillId])
        ->and($course->mappedSkills()->pluck('id')->all())->toBe([$skillId]);
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

test('reviseCourse does not change availability or skip lifecycle events (#91)', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));
    $store->deactivateCourse($companyEntityId, (int) $course->id);

    Event::fake([TrainingCourseDeactivated::class, TrainingCourseReactivated::class]);

    // Ordinary draft defaults active=true — must not silently reactivate.
    $revised = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'title' => 'Forklift Induction (Content only)',
    ]));
    expect($revised->active)->toBeFalse()
        ->and($revised->title)->toBe('Forklift Induction (Content only)');

    // Explicit active:false on revise must not deactivate via the revise path either.
    $store->reactivateCourse($companyEntityId, (int) $course->id);
    Event::fake([TrainingCourseDeactivated::class, TrainingCourseReactivated::class]);

    $stillActive = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'active' => false,
        'title' => 'Still active after revise',
    ]));
    expect($stillActive->active)->toBeTrue()
        ->and($stillActive->title)->toBe('Still active after revise');

    Event::assertNotDispatched(TrainingCourseDeactivated::class);
    Event::assertNotDispatched(TrainingCourseReactivated::class);
});

test('a course cannot be reached, revised, or deactivated across a sibling company in the same tenant', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $siblingCompanyId = (int) trainingCatalogWorkforceEntity($tenantId, 'company')->id;
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => $store->reviseCourse($siblingCompanyId, (int) $course->id, trainingCourseDraft($skillId)))
        ->toThrow(TrainingCatalogRecordNotFoundException::class)
        ->and(fn () => $store->deactivateCourse($siblingCompanyId, (int) $course->id))
        ->toThrow(TrainingCatalogRecordNotFoundException::class)
        ->and(fn () => $store->reactivateCourse($siblingCompanyId, (int) $course->id))
        ->toThrow(TrainingCatalogRecordNotFoundException::class);
});

test('an internal trainer must be an active employee projection in the selected company', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();

    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => 999_999,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');

    $trainer = trainingCatalogWorkforceEntity($tenantId, 'employee');
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => (int) $trainer->id,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'active internal trainer');
});

test('entity references are checked by workforce type, not merely by existing in the tenant', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $employeeEntity = trainingCatalogWorkforceEntity($tenantId, 'employee');

    // A real employee entity used as the company argument must be refused —
    // an id existing somewhere in the tenant is not the same as it being the
    // right kind of workforce entity for the field it is passed to.
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse((int) $employeeEntity->id, trainingCourseDraft($skillId)))
        ->toThrow(InvalidTrainingCatalogException::class, 'company workforce entity');

    // Symmetrically, a real company entity used as the trainer must be
    // refused — the trainer field names an employee, not any workforce entity.
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => $companyEntityId,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');
});

test('a training course table row participates in company isolation like the skill tables it references', function (): void {
    expect(is_a(TrainingCourse::class, ReferencesWorkforceEntities::class, true))->toBeTrue();

    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => TrainingCourse::query()->where('id', $course->id)->get())
        ->toThrow(MissingCompanyScopeException::class);
});
