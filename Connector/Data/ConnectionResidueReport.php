<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What a retired connection still binds, table by table.
 *
 * Retirement freezes the connection; it does not erase what it recorded. This
 * report is the inventory an operator reads before deliberately retaining or
 * removing that residue.
 */
final readonly class ConnectionResidueReport
{
    /**
     * @param  list<ConnectionResidueRow>  $rows
     */
    public function __construct(
        public int $connectionId,
        public array $rows,
    ) {}

    public function needsDecision(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->flagged) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function flags(): array
    {
        return array_values(array_filter(array_map(
            static fn (ConnectionResidueRow $row): ?string => $row->flagged
                ? ($row->flagReason ?? $row->table)
                : null,
            $this->rows,
        )));
    }
}
