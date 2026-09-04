<?php

namespace App\Domains\PeopleConnector\Skill\Events;

final class SkillAssessmentFinalized
{
    public function __construct(
        public int $tenantId,
        public int $assessmentId,
        public int $employeeEntityId,
        public int $skillId,
    ) {}
}
