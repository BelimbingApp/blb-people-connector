<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;

/**
 * A refusal carries its reason as a typed value, not only as prose.
 *
 * The message explains it to a person reading a stack trace; the refusal is
 * what the two transports are compared on, because a boolean cannot tell that
 * one path refused for a different reason than the other.
 */
final class DelegatedAuthorityException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?DelegatedAuthorityRefusal $refusal = null,
    ) {
        parent::__construct($message);
    }
}
