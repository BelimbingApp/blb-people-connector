<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use DateTimeInterface;

/**
 * Input for drafting or superseding an employee skill assessment.
 * Requirement snapshots are taken at finalize time from ResolvesSkillRequirements.
 */
final readonly class AssessmentDraft
{
    public function __construct(
        public int $employeeEntityId,
        public int $skillId,
        public int $assessedLevel,
        public AssessmentMethod $method,
        public AssessmentCycle $cycle,
        public DateTimeInterface $assessedAt,
        public string $evidence,
        public ?string $notes = null,
        public ?int $assessorUserId = null,
        public ?int $assessorEmployeeEntityId = null,
        public ?string $certificateNumber = null,
        public ?DateTimeInterface $validUntil = null,
        public ?int $scaleId = null,
        public ?int $scaleVersion = null,
        public ?float $weightPercent = null,
    ) {}
}
