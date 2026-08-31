<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;

final class ProviderRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $providers = [];

    public function register(ProviderAdapter $provider): void
    {
        $descriptor = $provider->descriptor();
        $supportedMajor = (int) config('people-connector.supported_contract_major', 1);

        if ($descriptor->contractMajor() !== $supportedMajor) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: 'register',
                message: "Provider contract {$descriptor->contractVersion} is incompatible with supported major {$supportedMajor}.",
            );
        }

        if (isset($this->providers[$descriptor->id])) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: 'register',
                message: "Provider '{$descriptor->id}' is already registered.",
            );
        }

        $this->providers[$descriptor->id] = $provider;
    }

    /** @return list<ProviderAdapter> */
    public function all(): array
    {
        $providers = $this->providers;
        ksort($providers);

        return array_values($providers);
    }

    public function active(): ?ProviderAdapter
    {
        $id = $this->configuredProviderId();

        return $id !== null ? ($this->providers[$id] ?? null) : null;
    }

    public function find(string $id): ?ProviderAdapter
    {
        return $this->providers[$id] ?? null;
    }

    public function configuredProviderId(): ?string
    {
        $id = config('people-connector.active_provider');

        return is_string($id) && trim($id) !== '' ? $id : null;
    }
}
