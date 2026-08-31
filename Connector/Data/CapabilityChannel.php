<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderPort;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;

final readonly class CapabilityChannel
{
    /**
     * @param  class-string|null  $portContract
     */
    public function __construct(
        public CapabilityDirection $direction,
        public CapabilityDelivery $delivery,
        public ?string $portContract = null,
        public ?string $providerUiUrl = null,
        public ?string $notes = null,
    ) {
        if ($delivery === CapabilityDelivery::ProviderUi) {
            if ($direction !== CapabilityDirection::None || $portContract !== null) {
                throw new \InvalidArgumentException('Provider UI hand-off cannot imply a connector data direction or port.');
            }

            if (! $this->isSafeProviderUiUrl($providerUiUrl)) {
                throw new \InvalidArgumentException('Provider UI capabilities require an HTTPS URL without embedded credentials or an absolute in-app path.');
            }

            return;
        }

        if ($providerUiUrl !== null) {
            throw new \InvalidArgumentException('A provider UI URL is valid only for provider_ui channels.');
        }

        if ($direction === CapabilityDirection::None) {
            throw new \InvalidArgumentException('Connector data channels must declare a read or write direction.');
        }

        if ($portContract === null
            || ! interface_exists($portContract)
            || ! is_a($portContract, ProviderPort::class, true)) {
            throw new \InvalidArgumentException('Connector data channels require an existing provider-neutral port interface.');
        }
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
