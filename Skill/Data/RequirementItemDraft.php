<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;

/**
 * One skill requirement within a profile draft. Carries required proficiency
 * level, criticality/priority weight, evidence standard, and mandatory gate flag.
 */
final readonly class RequirementItemDraft
{
    public function __construct(
        public int $skillId,
        public int $sequence,
        public int $requiredLevel,
        public RequirementCriticality $criticality,
        public float $weightPercent,
        public ?string $evidenceStandard = null,
        public bool $mandatoryGate = false,
        public ?int $reassessmentMonths = null,
        public bool $active = true,
    ) {}
}
