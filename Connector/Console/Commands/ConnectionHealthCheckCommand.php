<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectionHealthChecker;
use Illuminate\Console\Command;

/**
 * Ping each active connection's adapter and report capability drift against
 * the evidence register (#209). Exits non-zero on drift, an unregistered
 * adapter, or an unavailable one: a deployment script reads the status.
 */
final class ConnectionHealthCheckCommand extends Command
{
    protected $signature = 'connector:health:check
                            {--tenant= : Tenant to check; defaults to the current tenant context}
                            {--as= : Id of the operator this check runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Ping every active connection\'s adapter and report capability drift against the evidence register';

    public function handle(TenantContext $tenants, ConnectionHealthChecker $checker): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A connection health check runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        if (($tenantId = $this->option('tenant')) !== null && $tenantId !== '') {
            $tenants->set((int) $tenantId);
        }

        try {
            $report = $checker->check(Actor::forUser($operator));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|InvalidProviderConfigurationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("Connection health check for tenant {$report->tenantId} against {$report->registerPath}");
            $this->table(['Connection', 'Provider', 'Health', 'Declared', 'Unsupported declared', 'Withdrawn'], array_map(static fn (array $row): array => [
                (string) $row['connection'],
                $row['provider'].($row['registered'] ? '' : ' (not registered)').($row['in_register'] ? '' : ' (not in register)'),
                $row['health'],
                implode(', ', $row['declared']),
                implode(', ', $row['unsupported_declared']),
                implode(', ', $row['withdrawn']),
            ], $report->rows));
            foreach ($report->blockers() as $blocker) {
                $this->warn('Blocked: '.$blocker);
            }
        }

        return $report->blocked() ? self::FAILURE : self::SUCCESS;
    }
}
