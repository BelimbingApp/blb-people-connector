<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\BackupRestoreRehearsalException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\BackupRestoreRehearsal;

final class BackupRestoreRehearsalCommand extends TenantScopedCommand
{
    protected $signature = 'connector:backup:rehearse
                            {--as= : Id of the operator running the rehearsal}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Export connector-owned tables and restore them into the configured empty scratch database';

    public function handle(BackupRestoreRehearsal $rehearsal): int
    {
        $operator = $this->operator();
        if ($operator === null) {
            return self::FAILURE;
        }

        try {
            $report = $rehearsal->run(Actor::forUser($operator));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|BackupRestoreRehearsalException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $rows = [];
            foreach ($report->sourceCounts as $table => $source) {
                $rows[] = [$table, $source, $report->scratchBefore[$table] ?? '-', $report->scratchAfter[$table] ?? '-'];
            }
            $this->table(['table', 'source', 'scratch before', 'scratch after'], $rows);
            $this->line('Redaction advisories: '.array_sum(array_map('count', $report->redactions)).'.');
            $report->successful()
                ? $this->info('Backup restore rehearsal matched every connector table.')
                : $this->error('Backup restore rehearsal found row-count mismatches.');
        }

        return $report->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function operator(): ?User
    {
        $operatorId = $this->option('as');
        if ($operatorId === null || $operatorId === '') {
            $this->error('A backup restore rehearsal runs as a named operator: pass --as=<user id>.');

            return null;
        }
        $operator = User::query()->find((int) $operatorId);
        if ($operator === null) {
            $this->error("No user [{$operatorId}].");
        }

        return $operator;
    }
}
