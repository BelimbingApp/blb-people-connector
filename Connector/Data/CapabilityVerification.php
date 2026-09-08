<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/** Outcome of connector:capability:verify (#231). */
final readonly class CapabilityVerification
{
    public function __construct(
        public string $providerId,
        public string $capability,
        public bool $recorded,
        public bool $declaredByAdapter,
        public string $message,
    ) {}
}
