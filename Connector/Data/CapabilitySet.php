<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;

final readonly class CapabilitySet
{
    /** @var array<string, CapabilityDeclaration> */
    private array $declarations;

    /** @param list<CapabilityDeclaration> $declarations */
    public function __construct(array $declarations)
    {
        $indexed = [];

        foreach ($declarations as $declaration) {
            if (! $declaration instanceof CapabilityDeclaration) {
                throw new \InvalidArgumentException('Capability sets accept only CapabilityDeclaration values.');
            }

            $key = $declaration->capability->value;
            if (isset($indexed[$key])) {
                throw new \InvalidArgumentException("Capability '{$key}' is declared more than once.");
            }

            $indexed[$key] = $declaration;
        }

        $this->declarations = $indexed;
    }

    public function direction(PeopleCapability $capability): CapabilityDirection
    {
        return isset($this->declarations[$capability->value])
            ? $this->declarations[$capability->value]->direction()
            : CapabilityDirection::None;
    }

    /** @return list<CapabilityDelivery> */
    public function deliveries(PeopleCapability $capability): array
    {
        $deliveries = [];

        foreach ($this->declarations[$capability->value]->channels ?? [] as $channel) {
            $deliveries[$channel->delivery->value] = $channel->delivery;
        }

        return array_values($deliveries);
    }

    public function canRead(PeopleCapability $capability): bool
    {
        return $this->direction($capability)->canRead();
    }

    public function canWrite(PeopleCapability $capability): bool
    {
        return $this->direction($capability)->canWrite();
    }

    /** @return list<class-string> */
    public function portContracts(PeopleCapability $capability): array
    {
        return isset($this->declarations[$capability->value])
            ? $this->declarations[$capability->value]->portContracts()
            : [];
    }

    /** @return list<class-string> */
    public function readPortContracts(PeopleCapability $capability): array
    {
        return $this->directionalPortContracts($capability, CapabilityDirection::Read);
    }

    /** @return list<class-string> */
    public function writePortContracts(PeopleCapability $capability): array
    {
        return $this->directionalPortContracts($capability, CapabilityDirection::Write);
    }

    /** @return list<string> */
    public function providerUiUrls(PeopleCapability $capability): array
    {
        return isset($this->declarations[$capability->value])
            ? $this->declarations[$capability->value]->providerUiUrls()
            : [];
    }

    /** @return list<CapabilityDeclaration> */
    public function all(): array
    {
        return array_values($this->declarations);
    }

    /** @return list<class-string> */
    private function directionalPortContracts(PeopleCapability $capability, CapabilityDirection $direction): array
    {
        $contracts = [];

        foreach ($this->declarations[$capability->value]->channels ?? [] as $channel) {
            $matches = $direction === CapabilityDirection::Read
                ? $channel->direction->canRead()
                : $channel->direction->canWrite();

            if ($matches && $channel->portContract !== null) {
                $contracts[$channel->portContract] = true;
            }
        }

        return array_keys($contracts);
    }
}
