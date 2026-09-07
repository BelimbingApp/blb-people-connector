<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ConnectionResidueReport;
use App\Domains\PeopleConnector\Connector\Data\ConnectionResidueRow;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionResidueException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory what a retired connection still binds, without changing any of it.
 *
 * Retirement freezes the connection; residue is what still points at it. The
 * report names each bound table, the domain's retention entry for that table,
 * and the decisions that are still open — unrevoked credentials, open issues,
 * current identities, and file exchange rows still in `recorded`.
 */
final class ConnectionResidueReporter
{
    public const LIST_CAPABILITY = 'people-connector.connection.list';

    /**
     * Tables a retired connection can still bind, in report order.
     *
     * @var list<array{0: string, 1: class-string<Model>, 2: string}>
     */
    private const TABLES = [
        ['people_connector_connector_external_identities', ExternalIdentity::class, 'connection_id'],
        ['people_connector_connector_sync_checkpoints', SyncCheckpoint::class, 'connection_id'],
        ['people_connector_connector_sync_checkpoint_events', SyncCheckpointEvent::class, 'checkpoint'],
        ['people_connector_connector_webhook_deliveries', WebhookDelivery::class, 'connection_id'],
        ['people_connector_connector_webhook_receipts', WebhookReceipt::class, 'connection_id'],
        ['people_connector_connector_file_exchange_records', FileExchangeRecord::class, 'provider_connection_id'],
        ['people_connector_connector_provider_credentials', ProviderCredentialRecord::class, 'connection_id'],
        ['people_connector_connector_reconciliation_issues', ReconciliationIssue::class, 'connection_id'],
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function for(Actor $actor, int $connectionId): ConnectionResidueReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::LIST_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'connection_residue',
                message: 'Listing connection residue reads one tenant and requires an operator inside it.',
            );
        }

        $connection = $this->connections->get($connectionId);

        if ($connection->status !== ProviderConnection::STATUS_RETIRED) {
            throw new ConnectionResidueException(
                'Connection residue is listed only for a retired connection; this one is still '.$connection->status.'.',
            );
        }

        $rows = [];

        foreach (self::TABLES as [$table, $model, $binding]) {
            $query = $this->boundQuery($tenantId, $connectionId, $model, $binding);
            $count = (clone $query)->count();
            [$flagged, $reason] = $this->flag($table, $query, $count);

            $rows[] = new ConnectionResidueRow(
                table: $table,
                count: $count,
                retentionDays: $this->retentionDays($table),
                flagged: $flagged,
                flagReason: $reason,
            );
        }

        $report = new ConnectionResidueReport($connectionId, $rows);

        $this->audit->record(
            $actor,
            OperatorAuditOperation::ConnectionResidueReported,
            $connectionId,
            null,
            null,
            [
                'tables' => count($rows),
                'flagged' => count($report->flags()),
            ],
            [
                'needs_decision' => $report->needsDecision(),
                'flags' => $report->flags(),
            ],
        );

        return $report;
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function boundQuery(int $tenantId, int $connectionId, string $model, string $binding): Builder
    {
        $query = $model::query()->forTenant($tenantId);

        if ($model === ProviderCredentialRecord::class) {
            $query->withoutCompanyScope(
                'Residue lists every credential bound to the retired connection, across companies.',
            );
        }

        if ($binding === 'checkpoint') {
            $checkpointIds = SyncCheckpoint::query()
                ->forTenant($tenantId)
                ->where('connection_id', $connectionId)
                ->select('id');

            return $query->whereIn('checkpoint_id', $checkpointIds);
        }

        return $query->where($binding, $connectionId);
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{0: bool, 1: ?string}
     */
    private function flag(string $table, Builder $query, int $count): array
    {
        if ($count === 0) {
            return [false, null];
        }

        return match ($table) {
            'people_connector_connector_external_identities' => $this->countFlag(
                (clone $query)->whereNull('replaced_by_identity_id')->count(),
                'current identity still unbound to a replacement',
            ),
            'people_connector_connector_provider_credentials' => $this->countFlag(
                (clone $query)->whereNull('revoked_at')->count(),
                'unrevoked credential',
            ),
            'people_connector_connector_reconciliation_issues' => $this->countFlag(
                (clone $query)->where('status', ReconciliationIssue::STATUS_OPEN)->count(),
                'open reconciliation issue',
            ),
            'people_connector_connector_file_exchange_records' => $this->countFlag(
                (clone $query)->where('status', FileExchangeRecord::STATUS_RECORDED)->count(),
                'file exchange still recorded (not archived or quarantined)',
            ),
            default => [false, null],
        };
    }

    /** @return array{0: bool, 1: ?string} */
    private function countFlag(int $flaggedCount, string $reason): array
    {
        return $flaggedCount > 0
            ? [true, $reason.' ('.$flaggedCount.')']
            : [false, null];
    }

    private function retentionDays(string $table): ?int
    {
        $rule = config('people-connector.retention.'.$table);

        if (! is_array($rule) || ! array_key_exists('days', $rule)) {
            throw new ConnectionResidueException(
                "Retention has no entry for [{$table}]; residue cannot invent one.",
            );
        }

        $days = $rule['days'];

        if ($days !== null && (! is_int($days) || $days < 1)) {
            throw new ConnectionResidueException(
                "Retention for [{$table}] must be a positive number of days, or null for kept.",
            );
        }

        return $days;
    }
}
