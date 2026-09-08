<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * One defect an HR2000 dry run found (#161). A defect is a stable reason code
 * bound to a row (null for the whole file) and, where useful, a column name.
 * It never carries a cell value: the file holds personnel data and a defect
 * list is printed and may be persisted.
 */
final readonly class Hr2000ImportDefect
{
    public function __construct(
        public ?int $row,
        public string $code,
        public ?string $field = null,
    ) {
        if (preg_match('/^[a-z]+(?:_[a-z]+)*$/', $code) !== 1 || ($row !== null && $row < 1)) {
            throw new \InvalidArgumentException('HR2000 import defects require a snake_case reason code and a positive row.');
        }
    }

    public function fileLevel(): bool
    {
        return $this->row === null;
    }

    /** @return array{row: int|null, code: string, field: string|null} */
    public function toArray(): array
    {
        return ['row' => $this->row, 'code' => $this->code, 'field' => $this->field];
    }
}
