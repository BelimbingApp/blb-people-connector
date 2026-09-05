<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/**
 * What is known about a command the connector sent to a provider.
 *
 * The distinction the whole contract turns on is between *not delivered* and
 * *unknown*. A command that never left may be retried; a command that reached
 * the provider and then timed out may not, because a timeout after delivery is
 * an unknown outcome and not proof of failure. Retrying that blind is how one
 * command becomes two.
 */
enum CommandOutcomeState: string
{
    case DeliveredAccepted = 'delivered_accepted';
    case DeliveredRejected = 'delivered_rejected';
    case NotDelivered = 'not_delivered';
    case Unknown = 'unknown';

    /**
     * A transport failure is classified by one fact: whether the command had
     * already left. Callers must decide that from the transport, not from the
     * absence of a reply.
     */
    public static function fromTransportFailure(bool $delivered): self
    {
        return $delivered ? self::Unknown : self::NotDelivered;
    }

    /** Whether the provider's answer is known, either way. */
    public function isSettled(): bool
    {
        return $this !== self::Unknown;
    }

    /** Only a command that demonstrably never left may be sent again as-is. */
    public function mayRetry(): bool
    {
        return $this === self::NotDelivered;
    }
}
