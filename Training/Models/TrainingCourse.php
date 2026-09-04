<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use Illuminate\Support\Collection;

/**
 * One training course/module in the company catalog: the "06 Training
 * Register" workbook's catalog identity. `code` is the stable company-scoped
 * Training ID and is immutable after creation. Scheduling, sessions,
 * enrollment, attendance and external-provider linkage are later slices —
 * see BelimbingApp/blb-people#14.
 */
class TrainingCourse extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_training_courses';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('internal_trainer_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TrainingCourse $course): void {
            if ($course->isDirty('code')) {
                throw new InvalidTrainingCatalogException(
                    "Training course code [{$course->getOriginal('code')}] is stable and cannot be changed.",
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'delivery_mode' => DeliveryMode::class,
            'active' => 'boolean',
        ];
    }

    /**
     * The skills this course is mapped to, pinned to this course's own
     * tenant and company.
     *
     * No public relation is exposed here, deliberately: see
     * SkillCategory::skillCount()'s docblock in the Skill module for why a
     * hasMany/belongsToMany on a company-owned model is an escape a caller
     * can widen. This returns a value, not a builder.
     *
     * @return Collection<int, Skill>
     */
    public function mappedSkills(): Collection
    {
        return Skill::query()
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)
            ->whereIn('id', $this->skillIds())
            ->get();
    }

    /** @return list<int> */
    public function skillIds(): array
    {
        // TrainingCourseSkill inherits company ownership from `course_id`
        // (see its companyScopeColumns()), so constraining that column here
        // satisfies RequireCompanyScope directly — no escape needed.
        return TrainingCourseSkill::query()
            ->where('course_id', $this->getKey())
            ->pluck('skill_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_course', 'id' => $this->getKey()];
    }
}
