<?php

namespace App\Domains\PeopleConnector\Training\Enums;

/**
 * Workbook-controlled delivery modes for a training course. See
 * BelimbingApp/blb-people#14's "Workbook parity contract".
 */
enum DeliveryMode: string
{
    case InternalOjt = 'internal_ojt';
    case InternalClassroom = 'internal_classroom';
    case ExternalClassroom = 'external_classroom';
    case Elearning = 'elearning';
    case VendorTraining = 'vendor_training';
    case Coaching = 'coaching';
    case ProjectAssignment = 'project_assignment';
    case JobRotation = 'job_rotation';
}
