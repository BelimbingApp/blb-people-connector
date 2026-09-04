<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingHodVerification = 'pending_hod_verification';
    case Returned = 'returned';
    case Finalized = 'finalized';
}
