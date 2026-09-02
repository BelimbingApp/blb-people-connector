<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use App\Domains\PeopleConnector\Skill\Enums\SelectorType;

/**
 * One target selector for a requirement profile draft. Determines which
 * employee cohort the profile applies to.
 */
final readonly class RequirementSelectorDraft
{
    public function __construct(
        public SelectorType $selectorType,
        public ?string $selectorValue = null,
        public ?int $selectorEntityId = null,
    ) {}
}
