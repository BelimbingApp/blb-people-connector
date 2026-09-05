<?php

namespace App\Domains\PeopleConnector\Training\Data;

/** Input captured before a request enters the governed approval workflow. */
final readonly class TrainingRequestDraft
{
    public function __construct(
        public string $title,
        public string $businessNeed,
        public int $requesterEmployeeEntityId,
        public ?int $departmentEntityId = null,
        public ?int $courseId = null,
        public ?string $skillReference = null,
        public ?string $developmentActionReference = null,
        public ?int $proposedBudgetMinor = null,
        public ?string $currency = null,
    ) {}
}
