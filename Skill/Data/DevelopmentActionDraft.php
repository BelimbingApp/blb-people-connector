<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionType;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use DateTimeInterface;

final readonly class DevelopmentActionDraft
{
    public function __construct(
        public int $employeeEntityId,
        public DevelopmentActionType $type,
        public string $objective,
        public string $intervention,
        public string $expectedEvidence,
        public int $ownerEmployeeEntityId,
        public int $hrCoordinatorEmployeeEntityId,
        public DateTimeInterface $startDate,
        public DateTimeInterface $dueDate,
        public ?int $trainerEmployeeEntityId = null,
        public ?string $trainerProviderName = null,
        public ?int $skillId = null,
        public ?int $startingLevel = null,
        public ?int $targetLevel = null,
        public ?RequirementCriticality $criticality = null,
        public bool $mandatoryGate = false,
        public ?string $nextSteps = null,
        public ?string $trainingCourseCode = null,
        public ?string $manualReason = null,
    ) {}
}
