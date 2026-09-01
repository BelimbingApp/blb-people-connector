<?php

namespace App\Domains\PeopleConnector\Skill\Events;

/**
 * Fired when a skill is created or revised in the catalog.
 */
final readonly class SkillDefined
{
    public function __construct(
        public int $tenantId,
        public int $skillId,
        public string $code,
        public bool $created,
    ) {}
}
