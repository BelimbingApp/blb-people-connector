<?php

namespace App\Domains\PeopleConnector\Skill\Events;

final readonly class SkillReactivated
{
    public function __construct(
        public int $tenantId,
        public int $skillId,
        public string $code,
    ) {}
}
