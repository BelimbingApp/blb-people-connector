<?php

namespace App\Domains\PeopleConnector\Skill\Events;

final readonly class SkillDeactivated
{
    public function __construct(
        public int $tenantId,
        public int $skillId,
        public string $code,
    ) {}
}
