<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceSubjectImportResult
{
    public function __construct(
        public string $packageId,
        public int $workforceEntityId,
        public int $identityCount,
        public int $snapshotCount,
    ) {}

    /** @return array{package_id: string, workforce_entity_id: int, identity_count: int, snapshot_count: int} */
    public function toArray(): array
    {
        return [
            'package_id' => $this->packageId,
            'workforce_entity_id' => $this->workforceEntityId,
            'identity_count' => $this->identityCount,
            'snapshot_count' => $this->snapshotCount,
        ];
    }
}
