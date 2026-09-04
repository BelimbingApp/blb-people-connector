<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum DevelopmentActionClosure: string
{
    case Open = 'open';
    case PendingReassessment = 'pending_reassessment';
    case ClosedCompetent = 'closed_competent';
    case FurtherActionRequired = 'further_action_required';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PendingReassessment => 'Pending Reassessment',
            self::ClosedCompetent => 'Closed — Competent',
            self::FurtherActionRequired => 'Further Action Required',
            self::Cancelled => 'Cancelled',
        };
    }
}
