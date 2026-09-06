<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

/**
 * The capability evidence register as data (#209): per provider, which
 * PeopleCapability values have deployment evidence behind them.
 *
 * The prose register (docs/providers/hr2000-capability-evidence.md) is
 * where the evidence is argued; this file is what a command can compare an
 * adapter's declarations against. A provider absent from the register has
 * verified nothing, and an unknown capability name is a refused file, not a
 * silently ignored row.
 */
final class CapabilityEvidenceRegister
{
    /** @var array<string, list<string>>|null */
    private ?array $providers = null;

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        $path = config('people-connector.capability_register');

        return new self(is_string($path) && $path !== '' ? $path : __DIR__.'/../../docs/providers/capability-register.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function knows(string $providerId): bool
    {
        return array_key_exists($providerId, $this->providers());
    }

    /** @return list<string> PeopleCapability values with evidence for this provider */
    public function verified(string $providerId): array
    {
        return $this->providers()[$providerId] ?? [];
    }

    /** @return array<string, list<string>> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new InvalidProviderConfigurationException("The capability register [{$this->path}] cannot be read.");
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['providers'] ?? null)) {
            throw new InvalidProviderConfigurationException("The capability register [{$this->path}] needs a providers object.");
        }

        $providers = [];
        foreach ($decoded['providers'] as $providerId => $entry) {
            if (! is_string($providerId) || ! is_array($entry) || ! is_array($entry['verified'] ?? null) || ! array_is_list($entry['verified'])) {
                throw new InvalidProviderConfigurationException("The capability register entry for [{$providerId}] needs a verified list.");
            }
            $verified = [];
            foreach ($entry['verified'] as $capability) {
                if (! is_string($capability) || PeopleCapability::tryFrom($capability) === null) {
                    throw new InvalidProviderConfigurationException("The capability register names an unknown capability for [{$providerId}].");
                }
                $verified[] = $capability;
            }
            $providers[$providerId] = array_values(array_unique($verified));
        }

        return $this->providers = $providers;
    }
}
