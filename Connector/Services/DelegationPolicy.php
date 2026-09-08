<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;

/**
 * The delegation settings both transports read, read the same way.
 *
 * The signer and the port each need the skew bound, and the port needs the
 * service's own audience. Reading config in two places is how the two paths
 * start disagreeing, so both read it here and fail closed on a bad value.
 */
final class DelegationPolicy
{
    public static function clockSkewSeconds(): int
    {
        $seconds = config('people-connector.delegation.clock_skew_seconds', 30);

        if (! is_int($seconds) || $seconds < 0) {
            throw new DelegatedAuthorityException('people-connector.delegation.clock_skew_seconds must be a non-negative integer.', DelegatedAuthorityRefusal::Unconfigured);
        }

        return $seconds;
    }

    /**
     * The outgoing secret of a rotation in progress, or null when there is
     * none to consult.
     *
     * The strength rule that guards the current key guards this one too: a
     * key too weak to sign with is too weak to accept, so a short previous
     * secret is treated as absent rather than trusted. connector:doctor is
     * where that misconfiguration is reported.
     */
    public static function previousSecret(): ?string
    {
        $previous = config('people-connector.delegation.previous_secret');

        return is_string($previous) && strlen($previous) >= DelegatedAuthoritySigner::MINIMUM_SECRET_BYTES
            ? $previous
            : null;
    }

    /**
     * Whether an operator has put anything in the previous-secret slot at
     * all, usable or not. verify() asks the stricter question above; the
     * doctor asks this one, because a rotation left half-finished is exactly
     * what an operator needs told.
     */
    public static function previousSecretConfigured(): bool
    {
        $previous = config('people-connector.delegation.previous_secret');

        return is_string($previous) && $previous !== '';
    }

    /**
     * When the rotation overlap closes, or null when no readable expiry is
     * configured. An unreadable expiry is not a window: an old key with no
     * end date is a key nobody retired.
     */
    public static function previousSecretExpiresAt(): ?\DateTimeImmutable
    {
        $expiresAt = config('people-connector.delegation.previous_secret_expires_at');

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($expiresAt);
        } catch (\Exception) {
            return null;
        }
    }

    /** Whether a token signed with the previous secret may still be accepted at $now. */
    public static function previousSecretAcceptedAt(\DateTimeImmutable $now): bool
    {
        $expiresAt = self::previousSecretExpiresAt();

        // Strictly before: the expiry instant is when the overlap is over,
        // not the last moment it holds.
        return $expiresAt !== null && $now < $expiresAt;
    }

    public static function audience(): string
    {
        $audience = config('people-connector.delegation.audience');

        if (! is_string($audience) || trim($audience) === '') {
            throw new DelegatedAuthorityException('people-connector.delegation.audience must name this service.', DelegatedAuthorityRefusal::Unconfigured);
        }

        return $audience;
    }
}
