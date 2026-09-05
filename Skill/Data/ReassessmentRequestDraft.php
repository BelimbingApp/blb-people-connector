<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestSource;
use DateTimeInterface;

/**
 * Input for opening a connector-owned reassessment request.
 * Completing training or an action may create this row; it never changes proficiency by itself.
 */
final readonly class ReassessmentRequestDraft
{
    public function __construct(
        public int $employeeEntityId,
        public int $skillId,
        public DateTimeInterface $dueDate,
        public AssessmentCycle $cycle,
        public ReassessmentRequestSource $source,
        public int $targetLevel,
        public ?int $assignedEvaluatorUserId = null,
        public ?string $requiredEvidence = null,
        public ?int $sourceDevelopmentActionId = null,
        public ?int $sourceTrainingEventId = null,
        public ?int $sourceAssessmentId = null,
        public ?string $notes = null,
    ) {}
}
