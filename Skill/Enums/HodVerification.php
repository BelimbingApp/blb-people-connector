<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum HodVerification: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
