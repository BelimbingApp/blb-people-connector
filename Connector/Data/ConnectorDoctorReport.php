<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ConnectorDoctorReport
{
    /** @param list<array{check: string, status: string, count: int, detail: string}> $checks */
    public function __construct(public array $checks) {}

    /** Yellow is advisory (a rotation overlap in progress, #247); only red fails the doctor. */
    public function healthy(): bool
    {
        return collect($this->checks)->every(fn (array $row): bool => $row['status'] !== 'red');
    }

    /** @return array{checks: list<array{check: string, status: string, count: int, detail: string}>} */
    public function toArray(): array
    {
        return ['checks' => $this->checks];
    }
}
