<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class WorkforceSubjectImportResult
{
    /**
     * @param  array<string, array<string, list<array<string, mixed>>>>  $supplemental  blocks re-emitted verbatim for restorable exporters, keyed by exporter name
     * @param  list<string>  $notRestored  exporter names whose blocks this import did not restore
     */
    public function __construct(
        public string $packageId,
        public int $workforceEntityId,
        public int $identityCount,
        public int $snapshotCount,
        public array $supplemental = [],
        public array $notRestored = [],
    ) {}

    /** @return array{package_id: string, workforce_entity_id: int, identity_count: int, snapshot_count: int, supplemental: array<string, array<string, list<array<string, mixed>>>>, not_restored: list<string>} */
    public function toArray(): array
    {
        return [
            'package_id' => $this->packageId,
            'workforce_entity_id' => $this->workforceEntityId,
            'identity_count' => $this->identityCount,
            'snapshot_count' => $this->snapshotCount,
            'supplemental' => $this->supplemental,
            'not_restored' => $this->notRestored,
        ];
    }
}
