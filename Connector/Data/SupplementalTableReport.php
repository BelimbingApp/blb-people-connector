<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * One supplemental (Skills or Training) table as the retention review shows
 * it: provider-independent, never purged, retention indefinite, with the
 * acting tenant's row count so an operator can see the boundary holds.
 *
 * There is no expired count and no column because there is no window: these
 * rows outlive every connection, and a purge that named one is refused.
 */
final readonly class SupplementalTableReport
{
    public bool $providerIndependent;

    public ?int $days;

    public function __construct(
        public string $table,
        public int $rows,
    ) {
        $this->providerIndependent = true;
        $this->days = null;
    }

    public function isIndefinite(): bool
    {
        return true;
    }
}
