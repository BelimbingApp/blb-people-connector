<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;

final readonly class CapabilityChannel
{
    public CapabilityDirection $direction;

    /**
     * @param  class-string|null  $portContract
     */
    public function __construct(
        public CapabilityDelivery $delivery,
        public ?string $portContract = null,
        public ?string $providerUiUrl = null,
        public ?string $notes = null,
    ) {
        if ($delivery === CapabilityDelivery::ProviderUi) {
            if ($portContract !== null) {
                throw new \InvalidArgumentException('Provider UI hand-off cannot expose a connector data port.');
            }

            if (! $this->isSafeProviderUiUrl($providerUiUrl)) {
                throw new \InvalidArgumentException('Provider UI capabilities require an HTTPS URL without embedded credentials or an absolute in-app path.');
            }

            $this->direction = CapabilityDirection::None;

            return;
        }

        if ($providerUiUrl !== null) {
            throw new \InvalidArgumentException('A provider UI URL is valid only for provider_ui channels.');
        }

        if ($portContract === null
            || ! interface_exists($portContract)
            || ! is_a($portContract, ProviderPort::class, true)) {
            throw new \InvalidArgumentException('Connector data channels require an existing provider-neutral port interface.');
        }

        $read = is_a($portContract, ReadableProviderPort::class, true);
        $write = is_a($portContract, WritableProviderPort::class, true);

        $this->direction = match (true) {
            $read && $write => CapabilityDirection::ReadWrite,
            $read => CapabilityDirection::Read,
            $write => CapabilityDirection::Write,
            default => throw new \InvalidArgumentException('Provider ports must declare readable and/or writable semantics.'),
        };
    }

    private function isSafeProviderUiUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
