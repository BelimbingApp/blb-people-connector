<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;

final readonly class ProviderHealth
{
    public function __construct(
        public ProviderHealthState $state,
        public ?\DateTimeImmutable $checkedAt,
        public ?\DateTimeImmutable $lastSuccessfulSyncAt = null,
        public ?string $message = null,
    ) {
        if ($state !== ProviderHealthState::Unknown && $checkedAt === null) {
            throw new \InvalidArgumentException('Known provider health requires a check timestamp.');
        }
    }
}
