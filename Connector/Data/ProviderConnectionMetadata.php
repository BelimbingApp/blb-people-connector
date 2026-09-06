<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

final readonly class ProviderConnectionMetadata
{
    public function __construct(
        public ProviderConnectionMode $mode,
        public ?string $endpointOrigin = null,
        public ?int $integrationAccountId = null,
        public ?int $integrationConnectionId = null,
        public ?WebhookDeliveryPolicy $deliveryPolicy = null,
    ) {
        if ($endpointOrigin !== null) {
            $parts = parse_url($endpointOrigin);

            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                throw new InvalidProviderConfigurationException(
                    'Provider endpoints must be credential-free HTTPS origins without paths or query parameters.',
                );
            }
        }

        foreach ([$integrationAccountId, $integrationConnectionId] as $integrationRecordId) {
            if ($integrationRecordId !== null && $integrationRecordId < 1) {
                throw new InvalidProviderConfigurationException(
                    'Provider Integration record IDs must be positive integers.',
                );
            }
        }

        if ($mode === ProviderConnectionMode::RemoteHttp && $endpointOrigin === null) {
            throw new InvalidProviderConfigurationException('Remote HTTP provider connections require a public endpoint.');
        }
    }

    /** @return array<string, int|string|array{max_attempts: int, backoff_seconds: list<int>}> */
    public function toArray(): array
    {
        return array_filter([
            'mode' => $this->mode->value,
            'endpoint_origin' => $this->endpointOrigin,
            'integration_account_id' => $this->integrationAccountId,
            'integration_connection_id' => $this->integrationConnectionId,
            'webhook_delivery_policy' => $this->deliveryPolicy?->toArray(),
        ], static fn (int|string|array|null $value): bool => $value !== null);
    }
}
