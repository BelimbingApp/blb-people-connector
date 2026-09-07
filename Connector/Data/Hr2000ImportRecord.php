<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/** A typed employee parsed from one HR2000 row, with the provenance that binds it to the exact bytes it came from (#161). */
final readonly class Hr2000ImportRecord
{
    public function __construct(
        public int $row,
        public WorkforceEmployee $employee,
        public WorkforceProvenance $provenance,
    ) {
        if ($row < 2) {
            throw new \InvalidArgumentException('HR2000 import records come from data rows below the header.');
        }
    }
}
