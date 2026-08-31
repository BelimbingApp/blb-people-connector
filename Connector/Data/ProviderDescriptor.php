<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderDescriptor
{
    public function __construct(
        public string $id,
        public string $name,
        public string $adapterVersion,
        public string $contractVersion,
        public string $placement = 'remote_or_colocated',
    ) {
        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $id) !== 1) {
            throw new \InvalidArgumentException('Provider IDs use stable lowercase dot/dash notation.');
        }

        foreach (['name' => $name, 'adapterVersion' => $adapterVersion, 'contractVersion' => $contractVersion] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("Provider {$field} cannot be empty.");
            }
        }

        foreach (['adapterVersion' => $adapterVersion, 'contractVersion' => $contractVersion] as $field => $version) {
            if (! $this->isSemanticVersion($version)) {
                throw new \InvalidArgumentException("Provider {$field} must be a semantic version.");
            }
        }
    }

    public function contractMajor(): int
    {
        preg_match('/^(\\d+)\\./', $this->contractVersion, $matches);

        return (int) $matches[1];
    }

    private function isSemanticVersion(string $version): bool
    {
        $identifier = '(?:0|[1-9]\\d*|\\d*[A-Za-z-][0-9A-Za-z-]*)';
        $prerelease = "(?:-({$identifier}(?:\\.{$identifier})*))?";
        $build = '(?:\\+([0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*))?';

        return preg_match("/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*){$prerelease}{$build}$/D", $version) === 1;
    }
}
