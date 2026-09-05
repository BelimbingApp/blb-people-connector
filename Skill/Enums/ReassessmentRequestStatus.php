<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum ReassessmentRequestStatus: string
{
    case Open = 'open';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
