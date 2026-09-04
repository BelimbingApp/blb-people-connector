<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Requirement criticality level determining priority weight multipliers.
 * Workbook parity: Critical = 3x, Essential = 2x, Development = 1x.
 */
enum RequirementCriticality: string
{
    case Critical = 'critical';
    case Essential = 'essential';
    case Development = 'development';

    public function multiplier(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Essential => 2,
            self::Development => 1,
        };
    }
}
