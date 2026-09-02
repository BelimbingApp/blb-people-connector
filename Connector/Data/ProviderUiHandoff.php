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
        $fragment = is_array($parts) ? (string) ($parts['fragment'] ?? '') : '';
        $securityNow = new DateTimeImmutable(now()->toISOString());

        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) === null
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
            || $query === '') {
            throw new \InvalidArgumentException('Provider UI hand-offs require an absolute URL with a server-side handle.');
        }

        $handleValues = [];
        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            [$rawName, $rawValue] = array_pad(explode('=', $parameter, 2), 2, '');
            $name = urldecode($rawName);
            $value = urldecode($rawValue);

            if (preg_match('/(?:token|secret|password|credential|access_token|refresh_token|code)/', strtolower($name)) === 1) {
                throw new \InvalidArgumentException('Provider UI hand-offs must not carry reusable credentials in URL parameters.');
            }

            if ($name === 'handle') {
                $handleValues[] = $value;
            }
        }

        if (count($handleValues) !== 1 || ! hash_equals($oneTimeHandle, $handleValues[0])) {
            throw new \InvalidArgumentException('Provider UI hand-offs must bind the URL handle to the returned one-time handle.');
        }

        if ($fragment !== '') {
            throw new \InvalidArgumentException('Provider UI hand-offs must not use URL fragments that can leak browser state.');
        }

        if ($oneTimeHandle === '' || $expiresAt <= $securityNow
            || $expiresAt->getTimestamp() - $securityNow->getTimestamp() > 300) {
            throw new \InvalidArgumentException('Provider UI hand-offs require a non-empty handle with a five-minute maximum lifetime.');
        }
    }
}
