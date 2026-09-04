<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum DevelopmentActionType: string
{
    case OnTheJobTraining = 'on_the_job_training';
    case Coaching = 'coaching';
    case ClassroomTraining = 'classroom_training';
    case ExternalCourse = 'external_course';
    case JobRotation = 'job_rotation';
    case SupervisedPractice = 'supervised_practice';
    case SopChecklist = 'sop_checklist';
    case Certification = 'certification';
    case ImprovementProject = 'improvement_project';
    case RecruitBackfill = 'recruit_backfill';

    public function label(): string
    {
        return match ($this) {
            self::OnTheJobTraining => 'On-the-Job Training',
            self::Coaching => 'Coaching',
            self::ClassroomTraining => 'Classroom Training',
            self::ExternalCourse => 'External Course',
            self::JobRotation => 'Job Rotation',
            self::SupervisedPractice => 'Supervised Practice',
            self::SopChecklist => 'SOP/Checklist',
            self::Certification => 'Certification',
            self::ImprovementProject => 'Improvement Project',
            self::RecruitBackfill => 'Recruit/Backfill',
        };
    }

    public function requiresTrainer(): bool
    {
        return in_array($this, [
            self::OnTheJobTraining,
            self::Coaching,
            self::ClassroomTraining,
            self::ExternalCourse,
            self::JobRotation,
            self::SupervisedPractice,
            self::Certification,
        ], true);
    }
}
