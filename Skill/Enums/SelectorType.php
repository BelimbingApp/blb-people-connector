<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Target selector types for requirement profiles. Each selector type
 * determines which employee cohort the profile applies to based on
 * organization data from the active provider projection.
 */
enum SelectorType: string
{
    case Company = 'company';
    case Department = 'department';
    case JobTitle = 'job_title';
    case JobGrade = 'job_grade';
    case WorkforceClass = 'workforce_class';
    case Position = 'position';
}
