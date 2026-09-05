<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;

/**
 * Turn a delegated authority into something that survives a network hop, and
 * back again without trusting what came over the wire.
 *
 * HMAC over a canonical payload. The signature proves the claims were not
 * altered and nothing more: whether this tenant may be acted on is the
 * backend's question, asked separately in assertUsableBy().
 */
final class DelegatedAuthoritySigner
{
    private const MINIMUM_SECRET_BYTES = 32;

    public function sign(DelegatedAuthority $authority): string
    {
        $secret = self::secret();
        $lifetime = $authority->expiresAt->getTimestamp() - $authority->issuedAt->getTimestamp();

        // Short-lived is a property of the boundary, not a convention callers
        // are trusted to follow. Refusing at signing is the only place it can
        // be enforced for everyone.
        if ($lifetime > self::maxLifetimeSeconds()) {
            throw new DelegatedAuthorityException(
                'A delegated authority may live at most '.self::maxLifetimeSeconds()." seconds; this one asks for {$lifetime}.",
                DelegatedAuthorityRefusal::Unconfigured,
            );
        }

        $payload = self::encode($authority->claims());

        return $payload.'.'.self::signature($payload, $secret);
    }

    public function verify(string $token, string $expectedAudience, ?\DateTimeImmutable $now = null): DelegatedAuthority
    {
        $secret = self::secret();
        $now ??= new \DateTimeImmutable;
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DelegatedAuthorityException('A delegated authority token is a payload and a signature.', DelegatedAuthorityRefusal::Malformed);
        }

        [$payload, $signature] = $parts;

        // hash_equals, not ===: a timing-variable comparison here leaks the
        // signature a byte at a time.
        if (! hash_equals(self::signature($payload, $secret), $signature)) {
            throw new DelegatedAuthorityException('This delegated authority was not signed by this connector.', DelegatedAuthorityRefusal::Unsigned);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false) {
            throw new DelegatedAuthorityException('This delegated authority payload is not readable.', DelegatedAuthorityRefusal::Malformed);
        }

        $claims = json_decode($decoded, true);

        if (! is_array($claims)) {
            throw new DelegatedAuthorityException('This delegated authority payload is not a claim set.', DelegatedAuthorityRefusal::Malformed);
        }

        $authority = DelegatedAuthority::fromClaims($claims);

        // Audience binding stops a token minted for one service being replayed
        // against another that happens to trust the same key.
        if (! hash_equals($authority->audience, $expectedAudience)) {
            throw new DelegatedAuthorityException(
                "This authority is addressed to [{$authority->audience}], not [{$expectedAudience}].",
                DelegatedAuthorityRefusal::WrongAudience,
            );
        }

        if ($now > $authority->expiresAt) {
            throw new DelegatedAuthorityException('This authority has expired.', DelegatedAuthorityRefusal::Expired);
        }

        return $authority;
    }

    /** @param array<string, int|string|null> $claims */
    private static function encode(array $claims): string
    {
        ksort($claims);

        return rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
    }

    private static function signature(string $payload, string $secret): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    }

    /**
     * A short secret fails closed alongside a missing one. A short key is worse
     * than none: it looks configured.
     */
    private static function secret(): string
    {
        $secret = config('people-connector.delegation.secret');

        if (! is_string($secret) || strlen($secret) < self::MINIMUM_SECRET_BYTES) {
            throw new DelegatedAuthorityException(
                'people-connector.delegation.secret must be at least '.self::MINIMUM_SECRET_BYTES.' bytes; delegated authority is unavailable without it.',
                DelegatedAuthorityRefusal::Unconfigured,
            );
        }

        return $secret;
    }

    private static function maxLifetimeSeconds(): int
    {
        $seconds = config('people-connector.delegation.max_lifetime_seconds', 300);

        if (! is_int($seconds) || $seconds < 1) {
            throw new DelegatedAuthorityException('people-connector.delegation.max_lifetime_seconds must be a positive integer.', DelegatedAuthorityRefusal::Unconfigured);
        }

        return $seconds;
    }
}
