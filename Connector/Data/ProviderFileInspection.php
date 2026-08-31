<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderFileInspection
{
    /** @param list<string> $errors */
    public function __construct(
        public bool $accepted,
        public string $schemaVersion,
        public array $errors = [],
    ) {
        if (trim($schemaVersion) === '') {
            throw new \InvalidArgumentException('Provider file inspections require a schema version.');
        }

        foreach ($errors as $error) {
            if (! is_string($error) || trim($error) === '') {
                throw new \InvalidArgumentException('Provider file inspection errors must be non-empty strings.');
            }
        }

        if ($accepted === ($errors !== [])) {
            throw new \InvalidArgumentException('Accepted provider files cannot have errors, and rejected files require an explanation.');
        }
    }
}
