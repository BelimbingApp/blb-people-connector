<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * Counts produced by one company-scoped privacy deletion request.
 */
final readonly class PrivacyDeletionReport
{
    public function __construct(
        public int $companyEntityId,
        public int $employeesTombstoned,
        public int $organizationUnitsTombstoned,
        public int $positionsTombstoned,
        public int $snapshotsRedacted,
    ) {}
}
