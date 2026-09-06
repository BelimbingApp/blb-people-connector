<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\RetentionPurgeResult;
use App\Domains\PeopleConnector\Connector\Data\RetentionReport;
use App\Domains\PeopleConnector\Connector\Data\RetentionTableReport;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Executes one reviewed retention report, atomically or not at all. */
final class RetentionPurger
{
    public const PURGE_CAPABILITY = 'people-connector.retention.purge';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly RetentionPolicy $retention,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function purge(
        Actor $actor,
        RetentionReport $report,
        ?\DateTimeImmutable $executedAt = null,
    ): RetentionPurgeResult {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::PURGE_CAPABILITY);

        if ($report->tenantId !== $tenantId) {
            throw new RetentionPolicyException('A retention purge can only execute a report for the current tenant.');
        }

        return DB::transaction(function () use ($actor, $report, $tenantId, $executedAt): RetentionPurgeResult {
            $current = $this->retention->review($actor, $report->reviewedAt);

            if (! $this->sameReport($report, $current)) {
                throw new RetentionPolicyException('Retention counts or policy changed; re-run the retention report before purging.');
            }

            $idsByTable = [];
            foreach ($report->tables as $table) {
                if ($table->isIndefinite()) {
                    continue;
                }

                $ids = $this->expiredRows($table, $tenantId, $report->reviewedAt)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if (count($ids) !== $table->expired) {
                    throw new RetentionPolicyException('Retention counts changed; re-run the retention report before purging.');
                }

                $idsByTable[$table->table] = $ids;
            }

            $deleted = [];
            foreach ($report->tables as $table) {
                $ids = $idsByTable[$table->table] ?? [];
                $deleted[$table->table] = $ids === [] ? 0 : DB::table($table->table)
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $ids)
                    ->delete();

                if ($deleted[$table->table] !== count($ids)) {
                    throw new RetentionPolicyException('Retention rows changed during deletion; the purge was rolled back.');
                }
            }

            $runId = (string) Str::uuid();
            $executedAt ??= \DateTimeImmutable::createFromInterface(now());
            DB::table('people_connector_connector_retention_purge_audits')->insert(array_map(
                fn (RetentionTableReport $table): array => [
                    'tenant_id' => $tenantId,
                    'run_id' => $runId,
                    'operator_user_id' => $actor->id,
                    'table_name' => $table->table,
                    'retention_days' => $table->days,
                    'retention_column' => $table->column,
                    'expected_count' => $table->expired,
                    'deleted_count' => $deleted[$table->table],
                    'report_reviewed_at' => $report->reviewedAt,
                    'executed_at' => $executedAt,
                    'created_at' => $executedAt,
                ],
                array_values($report->tables),
            ));

            $this->audit->record(
                $actor,
                OperatorAuditOperation::RetentionPurged,
                null,
                null,
                $runId,
                ['expected' => array_map(fn (RetentionTableReport $table): int => $table->expired, array_values($report->tables)), 'tables' => array_map(fn (RetentionTableReport $table): string => $table->table, array_values($report->tables))],
                ['deleted' => array_values($deleted), 'total_deleted' => array_sum($deleted)],
                $executedAt,
            );

            return new RetentionPurgeResult($runId, $tenantId, $executedAt, $deleted);
        });
    }

    private function expiredRows(RetentionTableReport $table, int $tenantId, \DateTimeImmutable $reviewedAt): Builder
    {
        return DB::table($table->table)
            ->where('tenant_id', $tenantId)
            ->whereNotNull($table->column)
            ->where($table->column, '<', $reviewedAt->modify("-{$table->days} days"));
    }

    private function sameReport(RetentionReport $expected, RetentionReport $current): bool
    {
        return array_map(fn (RetentionTableReport $table): array => (array) $table, $expected->tables)
            === array_map(fn (RetentionTableReport $table): array => (array) $table, $current->tables);
    }
}
