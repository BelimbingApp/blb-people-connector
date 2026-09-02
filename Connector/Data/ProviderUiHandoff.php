<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use DateTimeImmutable;

final readonly class ProviderUiHandoff
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt,
        public string $oneTimeHandle,
    ) {
        $parts = parse_url($url);
        $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';

        if (! filter_var($url, FILTER_VALIDATE_URL) || $query === '') {
            throw new \InvalidArgumentException('Provider UI hand-offs require an absolute URL with a server-side handle.');
        }

        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            $name = strtolower((string) (explode('=', $parameter, 2)[0] ?? ''));
            if (preg_match('/(?:token|secret|password|credential|access_token|refresh_token|code)/', $name) === 1) {
                throw new \InvalidArgumentException('Provider UI hand-offs must not carry reusable credentials in URL parameters.');
            }
        }

        if ($oneTimeHandle === '' || $expiresAt <= new DateTimeImmutable) {
            throw new \InvalidArgumentException('Provider UI hand-offs require a non-empty, unexpired one-time handle.');
        }
    }
}
