<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;

/**
 * An in-memory supplemental exporter for the connector's own tests (#308). Its
 * store is keyed the way a People module keys its rows, by tenant, owning
 * company entity and subject, so a test can seed a sibling's or another
 * tenant's row beside the subject's and prove it never leaves. Every call is
 * recorded so a test can also prove the exporter was not consulted at all.
 */
final class SyntheticSupplementalExporter implements ExportsSupplementalSubjectRecords
{
    /** @var array<string, array<string, list<array<string, mixed>>>> key => table => rows */
    private array $store = [];

    /** @var list<array{tenant_id: int, company_entity_id: int, stable_id: string}> */
    private array $calls = [];

    public function __construct(
        private readonly string $name = 'people.synthetic',
        private readonly bool $restorable = false,
    ) {}

    /** @param array<string, mixed> $row */
    public function seed(int $tenantId, int $companyEntityId, int $workforceEntityId, string $table, array $row): void
    {
        $this->store[self::key($tenantId, $companyEntityId, (string) $workforceEntityId)][$table][] = $row;
    }

    /** @return list<array{tenant_id: int, company_entity_id: int, stable_id: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function restorable(): bool
    {
        return $this->restorable;
    }

    public function sections(WorkforceSubject $subject, int $tenantId, int $companyEntityId): array
    {
        $this->calls[] = ['tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId, 'stable_id' => $subject->stableId];

        return $this->store[self::key($tenantId, $companyEntityId, $subject->stableId)] ?? [];
    }

    private static function key(int $tenantId, int $companyEntityId, string $stableId): string
    {
        return "{$tenantId}:{$companyEntityId}:{$stableId}";
    }
}
