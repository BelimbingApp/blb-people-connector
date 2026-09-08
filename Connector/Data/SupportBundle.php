<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/** A written support bundle (#250): where it is, how big, and what its manifest says. */
final readonly class SupportBundle
{
    /** @param array<string, mixed> $manifest */
    public function __construct(public string $path, public int $bytes, public array $manifest) {}
}
