<?php

namespace App\Domains\PeopleConnector\Training\Events;

/**
 * Fired when a training course is deactivated in the catalog.
 */
final readonly class TrainingCourseDeactivated
{
    public function __construct(
        public int $tenantId,
        public int $courseId,
        public string $code,
    ) {}
}
