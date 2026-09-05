<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople\Exceptions;

/**
 * Raised inside the translator and turned into a ProviderValidationException at
 * the port boundary, where the provider id and operation are known. It carries
 * only the offending provider id — never the external id, which is
 * provider-owned data this adapter has already decided it cannot vouch for.
 */
final class ForeignProviderReferenceException extends \RuntimeException
{
    public function __construct(public readonly string $publishedProviderId)
    {
        parent::__construct('The People provider published a reference owned by another provider.');
    }
}
