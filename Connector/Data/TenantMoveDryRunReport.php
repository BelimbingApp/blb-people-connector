<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What a full tenant move would touch (#240), read without writing.
 *
 * @phpstan-type Collision array{source_identity: int, target_identity: int, resource_type: string, external_id_hash: string}
 */
final readonly class TenantMoveDryRunReport
{
    /**
     * @param  array<string, int>  $tables  connector-owned table => rows of the source tenant
     * @param  list<Collision>  $collisions
     */
    public function __construct(
        public int $sourceTenantId,
        public int $targetTenantId,
        public array $tables,
        public array $collisions,
        public int $inFlightDeliveries,
        public int $deadLetters,
    ) {}

    /** @return list<string> */
    public function blockers(): array
    {
        $blockers = [];
        if ($this->collisions !== []) {
            $blockers[] = count($this->collisions).' identity collision(s): external ids the target tenant already maps';
        }
        if ($this->inFlightDeliveries > 0) {
            $blockers[] = "{$this->inFlightDeliveries} webhook delivery(ies) still in flight";
        }
        if ($this->deadLetters > 0) {
            $blockers[] = "{$this->deadLetters} parked feed page(s) (dead letters) still open";
        }

        return $blockers;
    }

    public function blocked(): bool
    {
        return $this->blockers() !== [];
    }

    /** @return array{source: int, target: int, tables: array<string, int>, collisions: list<Collision>, in_flight_deliveries: int, dead_letters: int, blocked: bool, blockers: list<string>} */
    public function toArray(): array
    {
        return ['source' => $this->sourceTenantId, 'target' => $this->targetTenantId, 'tables' => $this->tables, 'collisions' => $this->collisions, 'in_flight_deliveries' => $this->inFlightDeliveries, 'dead_letters' => $this->deadLetters, 'blocked' => $this->blocked(), 'blockers' => $this->blockers()];
    }
}
