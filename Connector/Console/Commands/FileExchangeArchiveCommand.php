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
 * Archive one file-exchange ledger row; archived rows are final (#285).
 */
final class FileExchangeArchiveCommand extends TenantScopedCommand
{
    protected $signature = 'connector:file-exchange:archive
                            {record : Id of the file-exchange record}
                            {--as= : Id of the operator this archive runs as}';

    protected $description = 'Mark one file-exchange record archived with an audit row; does not touch file bytes';

    public function handle(FileExchangeOperator $files): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A file-exchange archive runs as a named operator: pass --as=<user id>.');

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

        try {
            $record = $files->archive(Actor::forUser($operator), (int) $recordId);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|FileExchangeException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("File-exchange record {$record->id} is archived (status={$record->status}); an audit row names operator {$operator->id}.");

        return self::SUCCESS;
    }
}
