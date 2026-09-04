<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Workbook result bands derived from gap (and assessed vs required when gap is 0).
 */
enum AssessmentResultBand: string
{
    case NotAssessed = 'not_assessed';
    case Exceeds = 'exceeds';
    case Meets = 'meets';
    case MinorGap = 'minor_gap';
    case MajorGap = 'major_gap';
    case CriticalGap = 'critical_gap';

    public static function fromGap(?int $gap, ?int $assessedLevel, ?int $requiredLevel): self
    {
        if ($gap === null || $assessedLevel === null || $requiredLevel === null) {
            return self::NotAssessed;
        }

        return match (true) {
            $gap === 0 && $assessedLevel > $requiredLevel => self::Exceeds,
            $gap === 0 => self::Meets,
            $gap === 1 => self::MinorGap,
            $gap === 2 => self::MajorGap,
            default => self::CriticalGap,
        };
    }
}
