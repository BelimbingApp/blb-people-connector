<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;

/**
 * Everything needed to define or revise one skill. Mirrors the workbook
 * columns; references are connector workforce entity ids.
 */
final readonly class SkillDraft
{
    public function __construct(
        public string $code,
        public string $name,
        public string $definition,
        public int $categoryId,
        public SkillScope $scope = SkillScope::Shared,
        public ?int $departmentEntityId = null,
        public ?CriticalClassification $criticalClassification = null,
        public ?string $evidenceGuide = null,
        public AssessmentMethod $defaultAssessmentMethod = AssessmentMethod::DirectObservation,
        public ?int $defaultReassessmentMonths = null,
        public ?int $ownerEmployeeEntityId = null,
        public bool $active = true,
    ) {}
}
