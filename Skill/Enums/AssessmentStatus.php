<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Finalized = 'finalized';
}
