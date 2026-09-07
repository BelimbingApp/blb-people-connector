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

    public static function audience(): string
    {
        $audience = config('people-connector.delegation.audience');

        if (! is_string($audience) || trim($audience) === '') {
            throw new DelegatedAuthorityException('people-connector.delegation.audience must name this service.', DelegatedAuthorityRefusal::Unconfigured);
        }

        return $audience;
    }
}
