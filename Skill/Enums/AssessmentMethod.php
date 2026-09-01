<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Controlled default assessment methods from the workbook. Assessments
 * (a later slice) may refine per-event method, but the catalog default
 * must come from this list.
 */
enum AssessmentMethod: string
{
    case DirectObservation = 'direct_observation';
    case PracticalDemonstration = 'practical_demonstration';
    case WrittenOralTest = 'written_oral_test';
    case WorkSampleReview = 'work_sample_review';
    case SystemDataReview = 'system_data_review';
    case ProjectEvidence = 'project_evidence';
    case Certification = 'certification';

    public function label(): string
    {
        return match ($this) {
            self::DirectObservation => 'Direct Observation',
            self::PracticalDemonstration => 'Practical Demonstration',
            self::WrittenOralTest => 'Written/Oral Test',
            self::WorkSampleReview => 'Work-Sample Review',
            self::SystemDataReview => 'System/Data Review',
            self::ProjectEvidence => 'Project Evidence',
            self::Certification => 'Certification',
        };
    }
}
