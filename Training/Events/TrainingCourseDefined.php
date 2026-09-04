<?php

namespace App\Domains\PeopleConnector\Training\Events;

/**
 * Fired when a training course is created or revised in the catalog.
 */
final readonly class TrainingCourseDefined
{
    public function __construct(
        public int $tenantId,
        public int $courseId,
        public string $code,
        public bool $created,
    ) {}
}
