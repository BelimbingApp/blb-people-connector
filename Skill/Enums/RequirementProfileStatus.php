<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Requirement profile lifecycle. Only Draft is editable. Review and approved
 * states are frozen while Workflow records accountable decisions; Published
 * and Retired remain immutable historical policy.
 */
enum RequirementProfileStatus: string
{
    case Draft = 'draft';
    case PendingHodReview = 'pending_hod_review';
    case PendingHrReview = 'pending_hr_review';
    case Approved = 'approved';
    case Published = 'published';
    case Retired = 'retired';

    public function mayTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::PendingHodReview,
            self::PendingHodReview => in_array($next, [self::Draft, self::PendingHrReview], true),
            self::PendingHrReview => in_array($next, [self::Draft, self::Approved], true),
            self::Approved => in_array($next, [self::Draft, self::Published], true),
            self::Published => $next === self::Retired,
            self::Retired => false,
        };
    }
}
