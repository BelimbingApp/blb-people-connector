<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum DevelopmentActionStatus: string
{
    case Proposed = 'proposed';
    case NotStarted = 'not_started';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case PendingReassessment = 'pending_reassessment';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::NotStarted => 'Not Started',
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In Progress',
            self::PendingReassessment => 'Pending Reassessment',
            self::Completed => 'Completed',
            self::OnHold => 'On Hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }
}
