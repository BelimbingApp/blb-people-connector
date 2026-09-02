<?php

namespace App\Domains\PeopleConnector\Skill\Data;

use DateTimeInterface;

/**
 * Everything needed to define or revise one requirement profile before
 * publishing. Carries metadata, target selectors, and requirement items.
 */
final readonly class RequirementProfileDraft
{
    /**
     * @param list<RequirementSelectorDraft> $selectors
     * @param list<RequirementItemDraft> $items
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $selectors,
        public array $items,
        public ?DateTimeInterface $effectiveDate = null,
        public ?int $ownerEmployeeEntityId = null,
    ) {}
}
