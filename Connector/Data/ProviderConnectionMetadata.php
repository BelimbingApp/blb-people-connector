<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

final readonly class ProviderConnectionMetadata
{
    public function __construct(
        public ProviderConnectionMode $mode,
        public ?string $endpoint = null,
        public ?string $accountReference = null,
        public ?string $integrationConnectionReference = null,
    ) {
        if ($endpoint !== null) {
            $parts = parse_url($endpoint);

            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                throw new InvalidProviderConfigurationException(
                    'Provider endpoints must be credential-free HTTPS origins or paths without query parameters.',
                );
            }
        }

        foreach ([$accountReference, $integrationConnectionReference] as $reference) {
            if ($reference !== null && (trim($reference) === '' || strlen($reference) > 191)) {
                throw new InvalidProviderConfigurationException('Provider public references must contain 1 to 191 bytes.');
            }
        }

        if ($mode === ProviderConnectionMode::RemoteHttp && $endpoint === null) {
            throw new InvalidProviderConfigurationException('Remote HTTP provider connections require a public endpoint.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'mode' => $this->mode->value,
            'endpoint' => $this->endpoint,
            'account_reference' => $this->accountReference,
            'integration_connection_reference' => $this->integrationConnectionReference,
        ], static fn (?string $value): bool => $value !== null);
    }
}
