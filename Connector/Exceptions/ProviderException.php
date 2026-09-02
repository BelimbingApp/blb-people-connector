<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

abstract class ProviderException extends \RuntimeException
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $providerId,
        public readonly string $operation,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
