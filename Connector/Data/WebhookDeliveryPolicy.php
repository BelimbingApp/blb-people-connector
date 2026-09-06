<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/** Immutable queue retry contract captured when a webhook delivery is accepted. */
final readonly class WebhookDeliveryPolicy
{
    /** @param list<int> $backoffSeconds */
    public function __construct(
        public int $maxAttempts,
        public array $backoffSeconds,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new InvalidProviderConfigurationException('Webhook delivery max attempts must be between 1 and 100.');
        }

        if (count($backoffSeconds) !== $maxAttempts - 1) {
            throw new InvalidProviderConfigurationException('Webhook delivery backoff must name one delay between each attempt.');
        }

        foreach ($backoffSeconds as $seconds) {
            if (! is_int($seconds) || $seconds < 0 || $seconds > 86400) {
                throw new InvalidProviderConfigurationException('Webhook delivery backoff values must be whole seconds between 0 and 86400.');
            }
        }
    }

    public static function defaults(): self
    {
        $settings = config('people-connector.webhook.delivery_policy', []);

        if (! is_array($settings)
            || ! is_int($settings['max_attempts'] ?? null)
            || ! is_array($settings['backoff_seconds'] ?? null)) {
            throw new InvalidProviderConfigurationException('The default webhook delivery policy is malformed.');
        }

        return new self($settings['max_attempts'], array_values($settings['backoff_seconds']));
    }

    public static function forConnection(ProviderConnection $connection): self
    {
        $settings = $connection->public_metadata['webhook_delivery_policy'] ?? null;

        if ($settings === null) {
            return self::defaults();
        }

        if (! is_array($settings)
            || ! is_int($settings['max_attempts'] ?? null)
            || ! is_array($settings['backoff_seconds'] ?? null)) {
            throw new InvalidProviderConfigurationException("Provider connection {$connection->id} has a malformed webhook delivery policy.");
        }

        return new self($settings['max_attempts'], array_values($settings['backoff_seconds']));
    }

    /** @return array{max_attempts: int, backoff_seconds: list<int>} */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
        ];
    }
}
