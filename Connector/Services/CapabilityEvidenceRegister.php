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
 *
 * A verified entry is either a bare capability name or an object with the
 * evidence behind it ({capability, evidence, verified_at, verified_by}), the
 * form connector:capability:verify appends (#231). Entries are appended,
 * never rewritten: removing evidence is a deliberate PR edit.
 */
final class CapabilityEvidenceRegister
{
    /** @var array<string, list<string>>|null */
    private ?array $providers = null;

    /** @var array<string, array<string, array{capability: string, evidence: ?string, verified_at: ?string, verified_by: ?string}>>|null */
    private ?array $entries = null;

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

    /** @return array{capability: string, evidence: ?string, verified_at: ?string, verified_by: ?string}|null */
    public function entry(string $providerId, PeopleCapability $capability): ?array
    {
        $this->providers();

        return $this->entries[$providerId][$capability->value] ?? null;
    }

    /**
     * Append one verified capability with its evidence. The file's other
     * content is carried as read; an entry already present is never touched.
     */
    public function append(string $providerId, PeopleCapability $capability, string $evidence, string $verifiedBy, \DateTimeImmutable $at): void
    {
        if ($this->entry($providerId, $capability) !== null) {
            throw new InvalidProviderConfigurationException("The capability register already verifies [{$capability->value}] for [{$providerId}].");
        }

        $decoded = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $decoded['providers'][$providerId]['verified'] ??= [];
        $decoded['providers'][$providerId]['verified'][] = [
            'capability' => $capability->value,
            'evidence' => $evidence,
            'verified_at' => $at->format(DATE_ATOM),
            'verified_by' => $verifiedBy,
        ];

        if (file_put_contents($this->path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
            throw new InvalidProviderConfigurationException("The capability register [{$this->path}] cannot be written.");
        }

        $this->providers = null;
        $this->entries = null;
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
            $entries = [];
            foreach ($entry['verified'] as $row) {
                $name = is_array($row) ? ($row['capability'] ?? null) : $row;
                if (! is_string($name) || PeopleCapability::tryFrom($name) === null) {
                    throw new InvalidProviderConfigurationException("The capability register names an unknown capability for [{$providerId}].");
                }
                $verified[] = $name;
                $entries[$name] ??= [
                    'capability' => $name,
                    'evidence' => is_array($row) && is_string($row['evidence'] ?? null) ? $row['evidence'] : null,
                    'verified_at' => is_array($row) && is_string($row['verified_at'] ?? null) ? $row['verified_at'] : null,
                    'verified_by' => is_array($row) && is_string($row['verified_by'] ?? null) ? $row['verified_by'] : null,
                ];
            }
            $providers[$providerId] = array_values(array_unique($verified));
            $this->entries[$providerId] = $entries;
        }

        return $this->providers = $providers;
    }
}
