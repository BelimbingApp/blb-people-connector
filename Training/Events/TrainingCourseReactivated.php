<?php

namespace App\Domains\PeopleConnector\Training\Events;

/**
 * Fired when a deactivated training course is reactivated. Distinct from
 * TrainingCourseDefined so a consumer can tell a revision from a
 * reactivation, mirroring Skill\Events\SkillReactivated.
 */
final readonly class TrainingCourseReactivated
{
    public function __construct(
        public int $tenantId,
        public int $courseId,
        public string $code,
    ) {}
}
