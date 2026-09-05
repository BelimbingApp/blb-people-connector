<?php

namespace App\Domains\PeopleConnector\Training\Enums;

/** A request is approval intent, never a participant or attendance record. */
enum TrainingRequestStatus: string
{
    case Draft = 'draft';
    case PendingHod = 'pending_hod';
    case PendingHr = 'pending_hr';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
