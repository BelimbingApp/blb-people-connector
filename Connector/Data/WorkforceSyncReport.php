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
        /**
         * Whether this pass moved the durable checkpoint. False for a refused
         * feed, and false for every replay, which re-reads without claiming
         * progress. It says nothing on its own about whether anything was
         * wrong — read feedRefused() for that.
         */
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

    /** Records the adapter sent, whether they were applied, deactivated, queued, refused or skipped as superseded. */
    public function seen(): int
    {
        return $this->applied() + $this->deactivations + $this->mergesQueued + $this->conflicts + $this->superseded;
    }

    /** A completed pass that carried no records at all. */
    public function empty(): bool
    {
        return $this->seen() === 0;
    }

    /**
     * The feed had records and every one of them was refused.
     *
     * Read from the counts rather than from checkpointAdvanced. Those two used
     * to be the same thing, because the only pass that declined to advance was
     * a refused one. A replay declines to advance every time and is usually
     * perfectly healthy, so equating the two now reports a clean replay as a
     * total refusal — the loudest possible signal for a pass that found nothing
     * wrong.
     */
    public function feedRefused(): bool
    {
        return $this->conflicts > 0 && $this->effected() === 0;
    }

    /** Records that changed state this pass: written, switched off, brought back, or queued for review. */
    public function effected(): int
    {
        return $this->applied() + $this->deactivations + $this->reactivations + $this->mergesQueued;
    }
}
