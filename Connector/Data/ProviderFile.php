<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFile
{
    public function __construct(
        public string $name,
        public string $sha256,
        public string $path,
        /** Bytes on disk as measured at construction (#301); null when the caller did not measure. */
        public ?int $sizeBytes = null,
    ) {
        if (trim($name) === '' || trim($path) === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || $sizeBytes < 0) {
            throw new \InvalidArgumentException('Provider files require a name, path, lowercase SHA-256, and a non-negative size.');
        }
    }
}
