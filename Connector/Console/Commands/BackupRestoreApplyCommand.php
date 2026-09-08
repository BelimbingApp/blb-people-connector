<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\BackupRestoreRehearsalException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ScratchBackupRestoreApplier;
use Throwable;

/** Internal fresh-process destination half of connector:backup:rehearse. */
final class BackupRestoreApplyCommand extends TenantScopedCommand
{
    protected $signature = 'connector:backup:rehearse-restore
                            {--as= : Id of the operator running the rehearsal}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Apply a private backup rehearsal handoff inside the configured scratch process';

    public function handle(ScratchBackupRestoreApplier $applier): int
    {
        $operatorId = $this->option('as');
        $operator = is_numeric($operatorId) ? User::query()->find((int) $operatorId) : null;
        if ($operator === null) {
            $this->error('The scratch restore requires the named operator provisioned in the scratch database.');

            return self::FAILURE;
        }

        try {
            $result = $applier->apply(Actor::forUser($operator));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|BackupRestoreRehearsalException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('The scratch restore could not complete safely.');

            return self::FAILURE;
        }

        $payload = ['before' => $result->before, 'after' => $result->after];
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
