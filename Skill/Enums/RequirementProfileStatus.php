<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Requirement profile lifecycle. Draft is editable; published is immutable
 * historical policy; retired stays readable for history but is no longer
 * the current profile.
 */
enum RequirementProfileStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
