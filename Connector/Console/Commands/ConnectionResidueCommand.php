<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ConnectionResidueReport;
use App\Domains\PeopleConnector\Connector\Data\ConnectionResidueRow;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionResidueException;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectionResidueReporter;

/**
 * List what a retired connection still binds, and exit non-zero while any flag
 * still needs a decision.
 */
final class ConnectionResidueCommand extends TenantScopedCommand
{
    protected $signature = 'connector:connection:residue
                            {connection : Id of the retired connection}
                            {--as= : Id of the operator this listing runs as}
                            {--json : Emit the report as JSON instead of a table}';

    protected $description = 'List what a retired connection still binds, with retention and outstanding decisions';

    public function handle(ConnectionResidueReporter $residue): int
    {
        $operatorId = $this->option('as');

        if ($operatorId === null || $operatorId === '') {
            $this->error('Connection residue runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }

        $operator = User::query()->find((int) $operatorId);

        if ($operator === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }

        try {
            $report = $residue->for(Actor::forUser($operator), (int) $this->argument('connection'));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|ConnectionResidueException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($this->payload($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report->needsDecision() ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Connection residue: connection {$report->connectionId}");
        $this->table(
            ['Table', 'Rows', 'Retention', 'Flag'],
            array_map(static fn (ConnectionResidueRow $row): array => [
                $row->table,
                (string) $row->count,
                $row->retentionLabel(),
                $row->flagged ? ($row->flagReason ?? 'yes') : 'no',
            ], $report->rows),
        );

        if (! $report->needsDecision()) {
            $this->line('No outstanding decisions. Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($report->flags() as $flag) {
            $this->warn('Decision needed: '.$flag);
        }

        return self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function payload(ConnectionResidueReport $report): array
    {
        return [
            'connection_id' => $report->connectionId,
            'needs_decision' => $report->needsDecision(),
            'flags' => $report->flags(),
            'rows' => array_map(static fn (ConnectionResidueRow $row): array => [
                'table' => $row->table,
                'count' => $row->count,
                'retention' => $row->retentionLabel(),
                'retention_days' => $row->retentionDays,
                'flagged' => $row->flagged,
                'flag_reason' => $row->flagReason,
            ], $report->rows),
        ];
    }
}
