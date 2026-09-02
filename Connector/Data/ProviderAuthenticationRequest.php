<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderAuthenticationRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public string $audience,
        public array $scopes,
    ) {
        if ($tenantId < 1 || $connectionId < 1) {
            throw new \InvalidArgumentException('Provider authentication requires positive tenant and connection IDs.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $audience) !== 1) {
            throw new \InvalidArgumentException('Provider authentication audiences must be stable lowercase identifiers.');
        }

        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), $scopes),
            static fn (string $scope): bool => $scope !== '',
        )));

        if ($normalized !== $scopes) {
            throw new \InvalidArgumentException('Provider authentication scopes must be unique, non-empty strings.');
        }

        foreach ($normalized as $scope) {
            if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $scope) !== 1) {
                throw new \InvalidArgumentException('Provider authentication scopes must be stable lowercase identifiers.');
            }
        }
    }
}
