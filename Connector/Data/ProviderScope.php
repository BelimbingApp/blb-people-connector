<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class ProviderScope
{
    private function __construct(public ?int $companyId) {}

    public static function tenant(): self
    {
        return new self(null);
    }

    public static function company(int $companyId): self
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('Provider company scopes require a positive company ID.');
        }

        return new self($companyId);
    }

    public function key(): string
    {
        return $this->companyId === null ? 'tenant' : 'company:'.$this->companyId;
    }
}
