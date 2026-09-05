<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * What one retirement did, and on whose authority.
 *
 * The review reference is here rather than only checked and discarded. A
 * required reference that evaporates is not an audit trail: a caller has to be
 * able to prove which reference authorised freezing a provider's history, the
 * way ProviderReplacementService already reports its own.
 */
final readonly class ConnectionRetirementReport
{
    public function __construct(
        public int $connectionId,
        public ProviderConnection $connection,
        public string $reviewReference,
        public \DateTimeImmutable $retiredAt,
    ) {}

    public function provenance(): WorkforceProvenance
    {
        return new WorkforceProvenance('connection.retirement', $this->reviewReference);
    }
}
