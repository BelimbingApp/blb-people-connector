<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\FileExchangeDiscoveryReport;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeDiscovery;

/**
 * Record what a scheduled export dropped in a connection's inbound directory
 * (#297). Tenant-scoped through the base command; the directory comes from
 * configuration, never from the command line, and the output names files,
 * hashes and statuses, never where they live. Exits non-zero when any entry
 * escaped the root or could not be read.
 */
final class FileExchangeDiscoverCommand extends TenantScopedCommand
{
    protected $signature = 'connector:file-exchange:discover
                            {--connection= : Id of the provider connection whose inbound directory to walk}
                            {--all : Walk the inbound directory of every active connection of the tenant}
                            {--as= : Id of the operator this run runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Record each new file in a connection\'s configured inbound directory in the file exchange ledger by SHA-256, touching no bytes';

    public function handle(TenantContext $tenants, FileExchangeDiscovery $discovery): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('File discovery runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }

        $connectionId = $this->option('connection');
        $all = (bool) $this->option('all');
        if ($all === ($connectionId !== null && $connectionId !== '')) {
            $this->error('Pass either --connection=<provider connection id> or --all.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        if ($all) {
            $connections = ProviderConnection::query()->forTenant($tenantId)->where('status', ProviderConnection::STATUS_ACTIVE)->orderBy('id')->get();
        } else {
            if (! is_scalar($connectionId) || preg_match('/^\d+$/', (string) $connectionId) !== 1) {
                $this->error('Pass --connection=<provider connection id>.');

                return self::FAILURE;
            }
            $connection = ProviderConnection::query()->forTenant($tenantId)->whereKey((int) $connectionId)->first();
            if ($connection === null) {
                $this->error("No provider connection [{$connectionId}] in this tenant.");

                return self::FAILURE;
            }
            $connections = collect([$connection]);
        }

        $actor = Actor::forUser($operator);
        $reports = [];

        try {
            foreach ($connections as $connection) {
                $reports[] = $discovery->discover($actor, $connection);
            }
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|FileExchangeException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['connections' => array_map(static fn (FileExchangeDiscoveryReport $r): array => $r->toArray(), $reports)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($reports as $report) {
                $counts = $report->counts();
                $this->line("Connection {$report->connectionId}: {$counts['recorded']} recorded, {$counts['already_recorded']} already recorded, {$counts['refused']} refused. No file was moved or parsed.");
                $this->table(['file', 'sha256', 'status', 'schema'], array_map(static fn (array $row): array => [
                    $row['file'],
                    $row['sha256'] === null ? '' : substr($row['sha256'], 0, 12),
                    $row['reason'] === null ? $row['status'] : $row['status'].' ('.$row['reason'].')',
                    $row['schema'] ?? '',
                ], $report->files));
            }
        }

        foreach ($reports as $report) {
            if ($report->refused()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
