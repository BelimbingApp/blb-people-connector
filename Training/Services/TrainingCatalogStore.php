<?php

namespace App\Domains\PeopleConnector\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Events\TrainingCourseDeactivated;
use App\Domains\PeopleConnector\Training\Events\TrainingCourseDefined;
use App\Domains\PeopleConnector\Training\Events\TrainingCourseReactivated;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Exceptions\TrainingCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingCourseSkill;
use Illuminate\Support\Facades\DB;

/**
 * Training-owned write path for the course catalog. Mirrors
 * Skill\Services\SkillCatalogStore's shape and its scoping discipline: every
 * lookup is bound to the tenant and to one company workforce entity, and this
 * store scopes but does not authorize — see that class's docblock for the
 * full reasoning, which applies unchanged here.
 *
 * This is the catalog foundation slice of BelimbingApp/blb-people#14.
 * Scheduling, sessions, enrollment, attendance, HOD approval surfaces, and
 * external-provider linkage (PeopleReferenceEntry::TYPE_TRAINING_PROVIDER
 * reuse) are deliberately not in this slice.
 */
class TrainingCatalogStore
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,79}$/';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function defineCourse(int $companyEntityId, TrainingCourseDraft $draft): TrainingCourse
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $this->assertCode($draft->code);
        $this->assertEntity($tenantId, $companyEntityId, WorkforceResourceType::Company, 'company_entity_id');
        $this->assertDraft($tenantId, $companyEntityId, $draft);

        if (TrainingCourse::query()->forCompany($tenantId, $companyEntityId)->where('code', $draft->code)->exists()) {
            throw new InvalidTrainingCatalogException("Training course code [{$draft->code}] already exists for this company.");
        }

        // The event fires only after this returns, deliberately: a listener
        // must never observe a course whose write rolled back. DB::transaction()
        // rethrows on failure, so a thrown exception here skips event() entirely.
        $course = DB::transaction(function () use ($tenantId, $companyEntityId, $draft): TrainingCourse {
            $course = TrainingCourse::query()->create(
                $this->attributesFor($draft) + [
                    'tenant_id' => $tenantId,
                    'company_entity_id' => $companyEntityId,
                    'code' => $draft->code,
                ],
            );

            $this->syncSkills($course, $draft->skillIds);

            return $course;
        });

        event(new TrainingCourseDefined($tenantId, (int) $course->getKey(), $course->code, created: true));

        return $course;
    }

    /**
     * Revise a course. The code is the stable company-scoped Training ID and
     * cannot change; a draft carrying a different code is refused (and the
     * column is additionally guarded at the model layer).
     */
    public function reviseCourse(int $companyEntityId, int $courseId, TrainingCourseDraft $draft): TrainingCourse
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $course = $this->requireCourse($companyEntityId, $courseId);

        if ($draft->code !== $course->code) {
            throw new InvalidTrainingCatalogException(
                "Training course code [{$course->code}] is stable and cannot be changed to [{$draft->code}]. Deactivate this course and define a new one instead.",
            );
        }

        $this->assertDraft($tenantId, $companyEntityId, $draft);

        // Availability is owned by deactivateCourse / reactivateCourse so those
        // lifecycle events stay the only writers of `active` (blb-people-connector#91).
        $course = DB::transaction(function () use ($course, $draft): TrainingCourse {
            $course->update($this->reviseAttributesFor($draft));
            $this->syncSkills($course, $draft->skillIds);

            return $course;
        });

        event(new TrainingCourseDefined($tenantId, (int) $course->getKey(), $course->code, created: false));

        return $course;
    }

    public function deactivateCourse(int $companyEntityId, int $courseId): TrainingCourse
    {
        $course = $this->requireCourse($companyEntityId, $courseId);

        if ($course->active) {
            $course->update(['active' => false]);
            event(new TrainingCourseDeactivated((int) $course->tenant_id, (int) $course->getKey(), $course->code));
        }

        return $course;
    }

    public function reactivateCourse(int $companyEntityId, int $courseId): TrainingCourse
    {
        $course = $this->requireCourse($companyEntityId, $courseId);

        if (! $course->active) {
            $course->update(['active' => true]);
            event(new TrainingCourseReactivated((int) $course->tenant_id, (int) $course->getKey(), $course->code));
        }

        return $course;
    }

    private function requireCourse(int $companyEntityId, int $courseId): TrainingCourse
    {
        return TrainingCourse::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->find($courseId)
            ?? throw new TrainingCatalogRecordNotFoundException("Training course [$courseId] was not found.");
    }

    /** @return array<string, mixed> */
    private function attributesFor(TrainingCourseDraft $draft): array
    {
        return [
            'title' => $draft->title,
            'description' => $draft->description,
            'delivery_mode' => $draft->deliveryMode,
            'internal_trainer_employee_entity_id' => $draft->internalTrainerEmployeeEntityId,
            'active' => $draft->active,
        ];
    }

    /**
     * Revise payload excludes `active` — toggling availability must go through
     * {@see deactivateCourse()} / {@see reactivateCourse()} so the published
     * lifecycle events cannot be skipped (blb-people-connector#91).
     *
     * @return array<string, mixed>
     */
    private function reviseAttributesFor(TrainingCourseDraft $draft): array
    {
        $attributes = $this->attributesFor($draft);
        unset($attributes['active']);

        return $attributes;
    }

    private function syncSkills(TrainingCourse $course, array $skillIds): void
    {
        TrainingCourseSkill::query()->where('course_id', $course->getKey())->delete();

        foreach (array_unique($skillIds) as $skillId) {
            TrainingCourseSkill::query()->create([
                'tenant_id' => $course->tenant_id,
                'course_id' => $course->getKey(),
                'skill_id' => $skillId,
            ]);
        }
    }

    private function assertDraft(int $tenantId, int $companyEntityId, TrainingCourseDraft $draft): void
    {
        if (trim($draft->title) === '') {
            throw new InvalidTrainingCatalogException('A training course needs a title.');
        }

        if ($draft->skillIds === []) {
            throw new InvalidTrainingCatalogException('A training course must map to at least one skill.');
        }

        $mappedCount = Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', array_unique($draft->skillIds))
            ->count();

        if ($mappedCount !== count(array_unique($draft->skillIds))) {
            throw new InvalidTrainingCatalogException('Every mapped skill must belong to the same company catalog.');
        }

        if ($draft->internalTrainerEmployeeEntityId !== null) {
            $this->assertEntity($tenantId, $draft->internalTrainerEmployeeEntityId, WorkforceResourceType::Employee, 'internal_trainer_employee_entity_id');
            WorkforceEmployeeProjection::query()->forCompany($tenantId, $companyEntityId)
                ->where('active', true)->where('workforce_entity_id', $draft->internalTrainerEmployeeEntityId)->first()
                ?? throw new InvalidTrainingCatalogException('Choose an active internal trainer from this company.');
        }
    }

    private function assertCode(string $code): void
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidTrainingCatalogException(
                'A training course code must be 1-80 lowercase letters, digits, dots, dashes, or underscores, starting with a letter or digit.',
            );
        }
    }

    private function assertEntity(int $tenantId, int $entityId, WorkforceResourceType $type, string $field): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($entityId);

        if ($entity === null || $entity->resource_type !== $type->value) {
            throw new InvalidTrainingCatalogException(
                "[$field] must reference an existing {$type->value} workforce entity in this tenant.",
            );
        }
    }
}
