<?php

namespace App\Domains\PeopleConnector\Skill\Events;

final readonly class ProficiencyScalePublished
{
    public function __construct(
        public int $tenantId,
        public int $scaleId,
        public string $code,
        public int $version,
        public ?int $retiredPreviousScaleId,
    ) {}
}
