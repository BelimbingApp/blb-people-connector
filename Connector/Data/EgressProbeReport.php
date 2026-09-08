<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class EgressProbeReport
{
    /**
     * @param list<array{
     *     connection_id: int,
     *     provider_id: string,
     *     host: ?string,
     *     port: ?int,
     *     outcomes: list<array{check: string, status: string, detail: string}>
     * }> $connections
     */
    public function __construct(public array $connections) {}

    public function healthy(): bool
    {
        foreach ($this->connections as $connection) {
            foreach ($connection['outcomes'] as $outcome) {
                if ($outcome['status'] === 'red') {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array{connection: int, provider: string, host: string, check: string, status: string, detail: string}> */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->connections as $connection) {
            foreach ($connection['outcomes'] as $outcome) {
                $rows[] = [
                    'connection' => $connection['connection_id'],
                    'provider' => $connection['provider_id'],
                    'host' => $connection['host'] === null ? '' : $connection['host'].':'.$connection['port'],
                    ...$outcome,
                ];
            }
        }

        return $rows;
    }

    /** @return array{connections: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['connections' => $this->connections];
    }
}
