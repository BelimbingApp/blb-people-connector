<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingCourseSkill;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function trainingImmutabilityEntity(int $tenantId, string $type): WorkforceEntity
{
    return WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
}

/**
 * Two companies in one tenant, each with a course mapped to a same-company skill.
 *
 * @return array{int, int, int, TrainingCourse, TrainingCourse, int, int, int}
 *                                                                             [tenantId, companyA, companyB, courseA, courseB, mappingAId, skillAId, skillBId]
 */
function trainingImmutabilitySiblingFixture(): array
{
    $tenant = createTenant(['name' => 'Training Immutability Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $companyA = trainingImmutabilityEntity((int) $tenant->id, 'company');
    $companyB = trainingImmutabilityEntity((int) $tenant->id, 'company');
    $catalog = app(SkillCatalogStore::class);
    $training = app(TrainingCatalogStore::class);

    $categoryA = $catalog->defineCategory((int) $companyA->id, 'safety', 'Safety A');
    $skillA = $catalog->defineSkill((int) $companyA->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $categoryA->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $courseA = $training->defineCourse((int) $companyA->id, new TrainingCourseDraft(
        code: 'forklift.induction',
        title: 'Forklift Induction',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skillA->id],
    ));

    $categoryB = $catalog->defineCategory((int) $companyB->id, 'safety', 'Safety B');
    $skillB = $catalog->defineSkill((int) $companyB->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $categoryB->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $courseB = $training->defineCourse((int) $companyB->id, new TrainingCourseDraft(
        code: 'forklift.induction.b',
        title: 'Forklift Induction B',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skillB->id],
    ));

    $mappingAId = (int) TrainingCourseSkill::query()
        ->where('course_id', $courseA->id)
        ->value('id');

    return [
        (int) $tenant->id,
        (int) $companyA->id,
        (int) $companyB->id,
        $courseA,
        $courseB,
        $mappingAId,
        (int) $skillA->id,
        (int) $skillB->id,
    ];
}

test('course codes are immutable at the model and database layers, not only in the store', function (): void {
    [$tenantId, $companyA, , $courseA] = trainingImmutabilitySiblingFixture();

    expect(fn () => $courseA->update(['code' => 'renamed.by.mass.assignment']))
        ->toThrow(InvalidTrainingCatalogException::class, 'stable');

    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => TrainingCourse::query()
        ->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
        ->whereKey($courseA->id)
        ->update(['code' => 'renamed.by.builder'])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_courses')
        ->where('id', $courseA->id)
        ->update(['code' => 'renamed.by.raw'])))
        ->toThrow(QueryException::class);

    expect($courseA->refresh()->code)->toBe('forklift.induction')
        ->and((int) $courseA->company_entity_id)->toBe($companyA)
        ->and((int) $courseA->tenant_id)->toBe($tenantId);
});

test('a pinned update cannot move a training course to a sibling company at the model or database layer', function (): void {
    [$tenantId, $companyA, $companyB, $courseA] = trainingImmutabilitySiblingFixture();

    foreach ([
        fn () => TrainingCourse::query()->forCompany($tenantId, $companyA)->update(['company_entity_id' => $companyB]),
        fn () => $courseA->fill(['company_entity_id' => $companyB])->save(),
        fn () => $courseA->forceFill(['company_entity_id' => $companyB])->save(),
    ] as $move) {
        expect($move)->toThrow(CompanyMoveRefusedException::class, 'would leave its company');
    }

    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => TrainingCourse::query()
        ->movingCompany('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
        ->forCompany($tenantId, $companyA)
        ->update(['company_entity_id' => $companyB])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_courses')
        ->where('id', $courseA->id)
        ->update(['company_entity_id' => $companyB])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect((int) $courseA->refresh()->company_entity_id)->toBe($companyA);
});

test('a course skill mapping cannot re-parent onto a sibling company course at the model or database layer', function (): void {
    [$tenantId, , , $courseA, $courseB, $mappingAId] = trainingImmutabilitySiblingFixture();

    expect(fn () => TrainingCourseSkill::query()
        ->where('course_id', $courseA->id)
        ->update(['course_id' => $courseB->id]))
        ->toThrow(CompanyMoveRefusedException::class, 'would leave its company');

    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => TrainingCourseSkill::query()
        ->movingCompany('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
        ->where('course_id', $courseA->id)
        ->update(['course_id' => $courseB->id])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_course_skills')
        ->where('id', $mappingAId)
        ->update(['course_id' => $courseB->id])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect((int) DB::table('people_connector_training_course_skills')->where('id', $mappingAId)->value('course_id'))
        ->toBe((int) $courseA->id)
        ->and($tenantId)->toBeInt();
});

test('training catalog company-owner guards permit only the documented merge survivor', function (): void {
    [$tenantId, $companyA, $companyB, $courseA, $courseB, $mappingAId] = trainingImmutabilitySiblingFixture();
    $stranger = (int) trainingImmutabilityEntity($tenantId, 'company')->id;

    $rawCourse = fn (int $owner): int => DB::table('people_connector_training_courses')
        ->where('id', $courseA->id)
        ->update(['company_entity_id' => $owner]);
    $rawMapping = fn (int $courseId): int => DB::table('people_connector_training_course_skills')
        ->where('id', $mappingAId)
        ->update(['course_id' => $courseId]);
    $refused = fn (callable $write) => expect(fn () => DB::transaction($write))
        ->toThrow(QueryException::class, 'cannot move to another company');

    // No merge recorded: neither destination is allowed.
    $refused(fn () => $rawCourse($companyB));
    $refused(fn () => $rawCourse($stranger));
    $refused(fn () => $rawMapping((int) $courseB->id));

    // A merged into B: stranger is the wrong survivor. While courseA still
    // names company A and courseB names company B, the Class D mapping may
    // re-parent onto courseB because A is already marked merged-into B.
    WorkforceEntity::query()->whereKey($companyA)->update([
        'state' => WorkforceEntity::STATE_MERGED,
        'merged_into_entity_id' => $companyB,
    ]);

    $refused(fn () => $rawCourse($stranger));
    expect($rawMapping((int) $courseB->id))->toBe(1)
        ->and((int) DB::table('people_connector_training_course_skills')->where('id', $mappingAId)->value('course_id'))
        ->toBe((int) $courseB->id);

    // Reverse mapping move afterwards is refused (B is not merged into A).
    $refused(fn () => $rawMapping((int) $courseA->id));

    // Course Class C owner move to the documented survivor is permitted;
    // reverse afterwards is refused.
    expect($rawCourse($companyB))->toBe(1)
        ->and((int) DB::table('people_connector_training_courses')->where('id', $courseA->id)->value('company_entity_id'))
        ->toBe($companyB);
    $refused(fn () => $rawCourse($companyA));
});

test('a training course cannot be deleted at the database layer', function (): void {
    [, , , $courseA, , $mappingAId] = trainingImmutabilitySiblingFixture();

    expect(fn () => DB::transaction(fn () => TrainingCourse::query()
        ->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
        ->whereKey($courseA->id)
        ->delete()))
        ->toThrow(QueryException::class, 'cannot be deleted');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_courses')
        ->where('id', $courseA->id)
        ->delete()))
        ->toThrow(QueryException::class, 'cannot be deleted');

    expect(TrainingCourse::query()
        ->withoutCompanyScope('Read-back after refused delete.')
        ->whereKey($courseA->id)
        ->exists())->toBeTrue()
        ->and(DB::table('people_connector_training_course_skills')->where('id', $mappingAId)->exists())->toBeTrue();
});

test('a course skill mapping cannot reassign to a sibling company skill at the database layer', function (): void {
    [$tenantId, , , $courseA, , $mappingAId, $skillAId, $skillBId] = trainingImmutabilitySiblingFixture();

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_course_skills')
        ->where('id', $mappingAId)
        ->update(['skill_id' => $skillBId])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_course_skills')->insert([
        'tenant_id' => $tenantId,
        'course_id' => $courseA->id,
        'skill_id' => $skillBId,
    ])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect((int) DB::table('people_connector_training_course_skills')->where('id', $mappingAId)->value('skill_id'))
        ->toBe($skillAId)
        ->and(DB::table('people_connector_training_course_skills')
            ->where('course_id', $courseA->id)
            ->where('skill_id', $skillBId)
            ->exists())->toBeFalse();
});

test('immutability migration preflight refuses legacy cross-company course-skill rows', function (): void {
    [, , , , , $mappingAId, $skillAId, $skillBId] = trainingImmutabilitySiblingFixture();

    $driver = DB::connection()->getDriverName();
    if ($driver === 'pgsql') {
        DB::unprepared('DROP TRIGGER IF EXISTS pct_course_skill_company_owner_guard_trigger ON people_connector_training_course_skills');
    } elseif ($driver === 'sqlite') {
        DB::statement('DROP TRIGGER IF EXISTS pct_course_skill_company_owner_guard');
        DB::statement('DROP TRIGGER IF EXISTS pct_course_skill_company_owner_insert_guard');
    }

    expect(DB::table('people_connector_training_course_skills')
        ->where('id', $mappingAId)
        ->update(['skill_id' => $skillBId]))->toBe(1);

    $migration = require dirname(__DIR__, 2).'/Database/Migrations/0330_03_02_000000_add_people_connector_training_catalog_immutability_guards.php';
    $method = new ReflectionMethod($migration, 'assertNoCrossCompanyCourseSkillMappings');

    expect(fn () => $method->invoke($migration))
        ->toThrow(RuntimeException::class, 'already cross company owners');

    // Restore a valid mapping so later tests / teardown stay clean.
    DB::table('people_connector_training_course_skills')
        ->where('id', $mappingAId)
        ->update(['skill_id' => $skillAId]);
});
