<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;

final readonly class CapabilityDeclaration
{
    /** @param non-empty-list<CapabilityChannel> $channels */
    public function __construct(
        public PeopleCapability $capability,
        public array $channels,
        public ?string $notes = null,
    ) {
        if ($channels === []) {
            throw new \InvalidArgumentException('Capability declarations require at least one delivery channel.');
        }

        $seen = [];
        foreach ($channels as $channel) {
            if (! $channel instanceof CapabilityChannel) {
                throw new \InvalidArgumentException('Capability declarations accept only CapabilityChannel values.');
            }

            $key = implode('|', [
                $channel->direction->value,
                $channel->delivery->value,
                $channel->portContract ?? '',
                $channel->providerUiUrl ?? '',
            ]);

            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Capability delivery channels cannot be duplicated.');
            }

            $seen[$key] = true;
        }
    }

    public function direction(): CapabilityDirection
    {
        $read = false;
        $write = false;

        foreach ($this->channels as $channel) {
            $read = $read || $channel->direction->canRead();
            $write = $write || $channel->direction->canWrite();
        }

        return match (true) {
            $read && $write => CapabilityDirection::ReadWrite,
            $read => CapabilityDirection::Read,
            $write => CapabilityDirection::Write,
            default => CapabilityDirection::None,
        };
    }

    /** @return list<class-string> */
    public function portContracts(): array
    {
        $contracts = [];

        foreach ($this->channels as $channel) {
            if ($channel->portContract !== null) {
                $contracts[$channel->portContract] = true;
            }
        }

        return array_keys($contracts);
    }

    /** @return list<string> */
    public function providerUiUrls(): array
    {
        $urls = [];

        foreach ($this->channels as $channel) {
            if ($channel->providerUiUrl !== null) {
                $urls[] = $channel->providerUiUrl;
            }
        }

        return $urls;
    }
}
