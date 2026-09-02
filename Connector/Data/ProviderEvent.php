<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
        if (trim($id) === '' || trim($type) === '') {
            throw new \InvalidArgumentException('Provider events require stable ID and type values.');
        }
    }
}
