<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

final class WebhookRefusal extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
