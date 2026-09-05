<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Workbook-facing coverage state for a projected employee skill score.
 * Expired evidence never counts as current critical-skill coverage.
 */
enum SkillCoverageState: string
{
    case Current = 'current';
    case DueSoon = 'due_soon';
    case Expired = 'expired';
    case Missing = 'missing';
}
