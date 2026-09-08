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
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;

/**
 * Quarantine one file-exchange ledger row with a short reason (#285).
 */
final class FileExchangeQuarantineCommand extends TenantScopedCommand
{
    protected $signature = 'connector:file-exchange:quarantine
                            {record : Id of the file-exchange record}
                            {--as= : Id of the operator this quarantine runs as}
                            {--reason= : Short reason (at most 190 characters, no paths)}';

    protected $description = 'Mark one file-exchange record quarantined with an audit row; does not touch file bytes';

    public function handle(FileExchangeOperator $files): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A file-exchange quarantine runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        $recordId = $this->argument('record');
        if (! is_scalar($recordId) || preg_match('/^\d+$/', (string) $recordId) !== 1) {
            $this->error('Pass a numeric file-exchange record id.');

            return self::FAILURE;
        }
        $reason = $this->option('reason');
        if (! is_string($reason) || trim($reason) === '') {
            $this->error('Pass --reason=<short line> explaining the quarantine.');

            return self::FAILURE;
        }
        if (strlen(trim($reason)) > OperatorAuditLog::MAX_STRING) {
            $this->error('A quarantine reason is at most '.OperatorAuditLog::MAX_STRING.' characters.');

            return self::FAILURE;
        }

        try {
            $record = $files->quarantine(Actor::forUser($operator), (int) $recordId, $reason);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|FileExchangeException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("File-exchange record {$record->id} is quarantined (status={$record->status}); an audit row names operator {$operator->id}.");

        return self::SUCCESS;
    }
}
