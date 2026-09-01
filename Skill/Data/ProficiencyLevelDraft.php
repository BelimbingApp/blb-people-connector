<?php

namespace App\Domains\PeopleConnector\Skill\Data;

/**
 * One level of a draft proficiency scale: the observable behaviour and the
 * work/training authority it grants.
 */
final readonly class ProficiencyLevelDraft
{
    public function __construct(
        public int $level,
        public string $name,
        public string $anchor,
        public string $authority,
    ) {}
}
