<?php

namespace App\Domains\PeopleConnector\Training\Data;

use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;

/**
 * Everything needed to define or revise one training course. Mirrors the
 * "06 Training Register" workbook columns this catalog owns; references are
 * connector workforce entity ids. External-provider and scheduling/session
 * fields are out of scope for this first slice — see
 * BelimbingApp/blb-people#14.
 */
final readonly class TrainingCourseDraft
{
    /**
     * @param  list<int>  $skillIds  Skill catalog ids this course covers. At
     *                               least one is required — a course with no
     *                               skill mapping cannot feed the reassessment
     *                               workflow the workbook contract requires.
     */
    public function __construct(
        public string $code,
        public string $title,
        public DeliveryMode $deliveryMode,
        public array $skillIds,
        public ?string $description = null,
        public ?int $internalTrainerEmployeeEntityId = null,
        public bool $active = true,
    ) {}
}
