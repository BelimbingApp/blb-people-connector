<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use DateTimeImmutable;

/**
 * Reads a connection's webhook signing secrets from config (#247).
 *
 * `people-connector.webhook.secrets.{connectionId}` is either one string
 * (the pre-rotation shape, unchanged) or a list of `{secret, expires_at?}`
 * entries, newest first. An entry with `expires_at` is a previous secret
 * kept for the overlap window; after it expires it verifies nothing.
 */
final class WebhookSecrets
{
    /** @return list<array{secret: string, expires_at: ?DateTimeImmutable}> as configured, newest first */
    public static function entries(int $connectionId): array
    {
        $configured = config("people-connector.webhook.secrets.{$connectionId}");
        if (is_string($configured)) {
            return $configured === '' ? [] : [['secret' => $configured, 'expires_at' => null]];
        }
        if (! is_array($configured) || ! array_is_list($configured)) {
            return [];
        }

        $entries = [];
        foreach ($configured as $entry) {
            if (! is_array($entry) || ! is_string($entry['secret'] ?? null) || $entry['secret'] === '') {
                return [];
            }
            $expires = $entry['expires_at'] ?? null;
            if ($expires !== null) {
                if (! is_string($expires) || ($parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $expires)) === false) {
                    return [];
                }
                $expires = $parsed;
            }
            $entries[] = ['secret' => $entry['secret'], 'expires_at' => $expires];
        }

        return $entries;
    }

    /** @return list<string> secrets that may verify a delivery at $now, newest first */
    public static function usable(int $connectionId, DateTimeImmutable $now): array
    {
        $usable = [];
        foreach (self::entries($connectionId) as $entry) {
            if ($entry['expires_at'] === null || $entry['expires_at'] > $now) {
                $usable[] = $entry['secret'];
            }
        }

        return $usable;
    }

    /** The earliest expiry among previous secrets still inside their overlap window, or null when none overlaps. */
    public static function overlapEndsAt(int $connectionId, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $earliest = null;
        foreach (self::entries($connectionId) as $entry) {
            if ($entry['expires_at'] !== null && $entry['expires_at'] > $now && ($earliest === null || $entry['expires_at'] < $earliest)) {
                $earliest = $entry['expires_at'];
            }
        }

        return $earliest;
    }

    /** First 8 hex of sha256: enough to tell secrets apart in an audit row, never enough to use one. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 8);
    }
}
