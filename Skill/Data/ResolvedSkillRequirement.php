<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;

/**
 * One skill requirement as seen by assessment and gap consumers.
 *
 * Deliberately omits how the requirement was chosen (selectors, profile type,
 * tier). BelimbingApp/blb-people#80 / [0002-c].
 */
final readonly class ResolvedSkillRequirement
{
    public function __construct(
        public string $requirementReference,
        public int $requirementVersion,
        public int $skillId,
        public int $requiredLevel,
        public RequirementCriticality $criticality,
        public bool $mandatoryGate = false,
    ) {}

    /**
     * Workbook gap: how many proficiency steps short of the requirement.
     */
    public function gap(int $currentValidLevel): int
    {
        return max($this->requiredLevel - $currentValidLevel, 0);
    }
}
