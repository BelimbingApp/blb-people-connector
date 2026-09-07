<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeOperator;

/**
 * List one connection's file-exchange ledger without paths or bytes (#285).
 */
final class FileExchangeListCommand extends TenantScopedCommand
{
    protected $signature = 'connector:file-exchange:list
                            {--as= : Id of the operator this listing runs as}
                            {--connection= : Id of the provider connection}
                            {--status= : recorded, quarantined or archived}
                            {--since= : Only rows recorded at or after this ISO 8601 time}
                            {--json : Emit machine-readable rows JSON}';

    protected $description = 'List file-exchange ledger rows for one connection (names, hashes, status; never paths or bytes)';

    public function handle(FileExchangeOperator $files): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A file-exchange listing runs as a named operator: pass --as=<user id>.');

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

        $since = $this->option('since');
        $sinceAt = null;
        if ($since !== null && $since !== '') {
            try {
                $sinceAt = new \DateTimeImmutable((string) $since);
            } catch (\Exception) {
                $this->error('--since must be an ISO 8601 time.');

                return self::FAILURE;
            }
        }

        $status = $this->option('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        try {
            $rows = $files->list(Actor::forUser($operator), (int) $connectionId, $status, $sinceAt);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|FileExchangeException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['id', 'file', 'direction', 'operation', 'sha256', 'bytes', 'schema', 'status', 'recorded_at'],
                array_map(static fn (array $row): array => [
                    (string) $row['id'],
                    $row['file_name'],
                    $row['direction'],
                    $row['operation'],
                    $row['sha256_prefix'],
                    (string) $row['byte_length'],
                    $row['schema_version'] ?? '',
                    $row['status'],
                    $row['recorded_at'],
                ], $rows),
            );
        }

        return self::SUCCESS;
    }
}
