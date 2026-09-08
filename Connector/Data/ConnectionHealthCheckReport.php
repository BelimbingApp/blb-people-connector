<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;

/**
 * One row per active connection (#209): the adapter's answer to its health
 * port and the drift between what it declares and what the evidence
 * register verifies. Health text from the adapter is deliberately not
 * carried: the state is the answer, the message is diagnostics.
 *
 * @phpstan-type Row array{connection: int, provider: string, registered: bool, in_register: bool, health: string, declared: list<string>, unsupported_declared: list<string>, withdrawn: list<string>}
 */
final readonly class ConnectionHealthCheckReport
{
    /** @param list<Row> $rows */
    public function __construct(public int $tenantId, public string $registerPath, public array $rows) {}

    /** An unsupported capability newly declared, an adapter that cannot be checked, or one that is unavailable. */
    public function blocked(): bool
    {
        return $this->blockers() !== [];
    }

    /** @return list<string> */
    public function blockers(): array
    {
        $blockers = [];
        foreach ($this->rows as $row) {
            if (! $row['registered']) {
                $blockers[] = "connection {$row['connection']}: adapter '{$row['provider']}' is not registered";
            }
            if ($row['unsupported_declared'] !== []) {
                $blockers[] = "connection {$row['connection']}: '{$row['provider']}' declares unverified capabilities ".implode(', ', $row['unsupported_declared']);
            }
            if ($row['health'] === ProviderHealthState::Unavailable->value) {
                $blockers[] = "connection {$row['connection']}: '{$row['provider']}' is unavailable";
            }
        }

        return $blockers;
    }

    /** @return array{tenant: int, register: string, blocked: bool, blockers: list<string>, connections: list<Row>} */
    public function toArray(): array
    {
        return ['tenant' => $this->tenantId, 'register' => $this->registerPath, 'blocked' => $this->blocked(), 'blockers' => $this->blockers(), 'connections' => $this->rows];
    }
}
