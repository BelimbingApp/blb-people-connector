<?php

namespace App\Domains\PeopleConnector\Skill\Events;

final readonly class RequirementProfilePublished
{
    public function __construct(
        public int $tenantId,
        public int $profileId,
        public string $code,
        public int $version,
        public ?int $retiredPreviousProfileId,
    ) {}
}
