<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;

/** Visible priority rule: gap x criticality multiplier; mandatory gates sort first. */
final class DevelopmentActionPriority
{
    public function score(int $gap, RequirementCriticality $criticality): int
    {
        return max($gap, 0) * $criticality->multiplier();
    }

    public function explanation(int $gap, RequirementCriticality $criticality, bool $mandatoryGate): string
    {
        $gate = $mandatoryGate ? 'Mandatory gate: yes; escalated ahead of non-gates. ' : 'Mandatory gate: no. ';

        return $gate."Score {$this->score($gap, $criticality)} = gap {$gap} × ".ucfirst($criticality->value)." multiplier {$criticality->multiplier()}.";
    }
}
