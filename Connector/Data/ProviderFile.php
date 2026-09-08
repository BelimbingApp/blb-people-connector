<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFile
{
    public string $name;

    public string $path;

    public function __construct(
        string $name,
        public string $sha256,
        string $path,
        /** Bytes on disk as measured at construction (#301); null when the caller did not measure. */
        public ?int $sizeBytes = null,
    ) {
        $name = trim($name);
        $path = trim($path);

        // The name is what list/audit surfaces: a basename, never a filesystem path.
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || $path === ''
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || $sizeBytes < 0
        ) {
            throw new \InvalidArgumentException(
                'Provider files require a basename (no path separators or control characters), a path, lowercase SHA-256, and a non-negative size.',
            );
        }

        $this->name = $name;
        $this->path = $path;
    }
}
