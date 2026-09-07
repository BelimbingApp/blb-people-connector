<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionMaintenanceException;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectionMaintenanceService;

/**
 * Start or end a planned maintenance window on one connection (#264).
 *
 * Tenant-scoped through the base command, which binds `--tenant` and refuses
 * an unknown tenant before handle() runs. Exits non-zero whenever nothing was
 * written, so a runbook step cannot report a refusal as success.
 */
final class ConnectionMaintenanceCommand extends TenantScopedCommand
{
    protected $signature = 'connector:connection:maintenance
                            {--as= : Id of the operator this change runs as}
                            {--connection= : Id of the provider connection}
                            {--until= : When the window ends (ISO 8601, in the future, at most 7 days away)}
                            {--reason= : Short reason shown to operators (190 characters)}
                            {--end : End the window now}';

    protected $description = 'Pause sync passes and defer webhook-triggered runs for one connection until a given time, with an audit row';

    public function handle(ConnectionMaintenanceService $maintenance): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A maintenance change runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        $connectionId = $this->option('connection');
        if (! is_scalar($connectionId) || preg_match('/^\d+$/', (string) $connectionId) !== 1) {
            $this->error('Pass --connection=<provider connection id>.');

            return self::FAILURE;
        }
        $until = $this->option('until');
        $end = (bool) $this->option('end');
        if ($end === ($until !== null && $until !== '')) {
            $this->error('Pass either --until=<time> to start a window or --end to end one.');

            return self::FAILURE;
        }

        $actor = Actor::forUser($operator);

        try {
            if ($end) {
                $connection = $maintenance->end($actor, (int) $connectionId);
                $this->line("Connection {$connection->id}: maintenance window ended; deferred webhook deliveries can now be replayed.");

                return self::SUCCESS;
            }

            try {
                $untilAt = new \DateTimeImmutable((string) $until);
            } catch (\Exception) {
                $this->error('--until must be an ISO 8601 time.');

                return self::FAILURE;
            }

            $connection = $maintenance->start($actor, (int) $connectionId, $untilAt, $this->option('reason'));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|ConnectionMaintenanceException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Connection {$connection->id}: in maintenance until {$connection->maintenance_until->format(DATE_ATOM)}; sync passes are skipped and webhook deliveries deferred until then.");

        return self::SUCCESS;
    }
}
