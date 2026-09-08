<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class BackupRestoreRehearsalReport
{
    /**
     * @param  array<string, int>  $sourceCounts
     * @param  array<string, int>  $scratchBefore
     * @param  array<string, int>  $scratchAfter
     * @param  array<string, mixed>  $redactions
     * @param  array<string, array{source: int, restored: int}>  $mismatches
     */
    public function __construct(
        public int $tenantId,
        public array $sourceCounts,
        public array $scratchBefore,
        public array $scratchAfter,
        public array $redactions,
        public array $mismatches,
    ) {}

    public function successful(): bool
    {
        return $this->mismatches === [] && array_sum($this->scratchBefore) === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'successful' => $this->successful(),
            'source_counts' => $this->sourceCounts,
            'scratch_before' => $this->scratchBefore,
            'scratch_after' => $this->scratchAfter,
            'redactions' => $this->redactions,
            'mismatches' => $this->mismatches,
        ];
    }
}
