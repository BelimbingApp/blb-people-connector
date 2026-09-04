<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Target selector types for requirement profiles. Each selector type
 * determines which employee cohort the profile applies to based on
 * organization data from the active provider projection.
 *
 * Only workforce resource types that exist today are allowed: company,
 * organization_unit (department), and position. Designation selectors
 * without a projection entity type are refused until the workforce model
 * grows them.
 */
enum SelectorType: string
{
    case Company = 'company';
    case Department = 'department';
    case Position = 'position';
}
