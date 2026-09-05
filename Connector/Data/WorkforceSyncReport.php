<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What one synchronisation pass did, counted from the writes it made rather
 * than from the pages it read. A pass that read three pages and applied
 * nothing says so here; "success" alone is never the whole report.
 */
final readonly class WorkforceSyncReport
{
    public function __construct(
        public int $connectionId,
        public string $stream,
        public string $pass,
        public int $pages,
        public int $companies,
        public int $organizationUnits,
        public int $positions,
        public int $employees,
        public int $deactivations,
        public int $reactivations,
        public int $mergesQueued,
        public int $conflicts,
        public int $checkpointVersion,
        public \DateTimeImmutable $asOf,
        /** False when the whole feed was refused: nothing changed and the pass must not be read as a success. */
        public bool $checkpointAdvanced = true,
        /** Changes a replay read but did not apply because current state already reflects something later. */
        public int $superseded = 0,
    ) {
        if (! in_array($pass, ['bootstrap', 'incremental', 'replay'], true)) {
            throw new \InvalidArgumentException('Workforce sync passes are bootstrap, incremental or replay.');
        }
    }

    /** Records whose facts were written to a projection this pass. */
    public function applied(): int
    {
        return $this->companies + $this->organizationUnits + $this->positions + $this->employees;
    }

    /** Records the adapter sent, whether they were applied, deactivated, queued or refused. */
    public function seen(): int
    {
        return $this->applied() + $this->deactivations + $this->mergesQueued + $this->conflicts;
    }

    /** A completed pass that carried no records at all. */
    public function empty(): bool
    {
        return $this->seen() === 0;
    }

    /** The feed had records and every one of them was refused; the checkpoint did not move. */
    public function feedRefused(): bool
    {
        return ! $this->checkpointAdvanced;
    }
}
