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

    public function label(): string
    {
        return match ($this) {
            self::InternalOjt => 'Internal OJT',
            self::InternalClassroom => 'Internal classroom',
            self::ExternalClassroom => 'External classroom',
            self::Elearning => 'E-learning',
            self::VendorTraining => 'Vendor training',
            self::Coaching => 'Coaching',
            self::ProjectAssignment => 'Project assignment',
            self::JobRotation => 'Job rotation',
        };
    }
}
