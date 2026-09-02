<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFile
{
    public function __construct(
        public string $name,
        public string $sha256,
        public string $path,
    ) {
        if (trim($name) === '' || trim($path) === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('Provider files require a name, path, and lowercase SHA-256.');
        }
    }
}
