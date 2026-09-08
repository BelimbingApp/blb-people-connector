<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ConnectionRetirementReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionRetirementException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use Illuminate\Support\Facades\DB;

/**
 * Retire a provider connection: stop it syncing and turn what it recorded into
 * read-only history.
 *
 * Nothing is erased. Identities, projections, checkpoints and snapshots stay
 * exactly where they are — People-owned records reference these entities, and
 * the plan is explicit that deactivation does not erase historical attribution.
 * What changes is that no new fact may be written through this connection.
 *
 * There is no "retired" flag on the projections. The connection already carries
 * the status, and every projection reaches it through its source identity;
 * copying the flag onto each row would be a second version of the same fact,
 * free to drift from the first.
 */
final class ConnectionRetirementService
{
    public const RETIRE_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function retire(
        Actor $actor,
        int $connectionId,
        string $reviewReference,
        ?\DateTimeInterface $occurredAt = null,
    ): ConnectionRetirementReport {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::RETIRE_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'retire_connection',
                message: 'Retiring a connection requires an actor inside its tenant.',
            );
        }

        if (trim($reviewReference) === '') {
            throw new ConnectionRetirementException(
                'Retiring a connection is an operator decision and requires a review reference.',
            );
        }

        // Built here so the reference is validated by the same rule every other
        // reviewed decision uses, before anything is written. Non-empty is not
        // the bar: this field is quoted back to an operator, so it has to be an
        // opaque identifier rather than prose.
        $provenance = new WorkforceProvenance('connection.retirement', trim($reviewReference));

        $this->connections->get($connectionId);

        $retiredAt = $occurredAt === null
            ? \DateTimeImmutable::createFromInterface(now())
            : \DateTimeImmutable::createFromInterface($occurredAt);

        return DB::transaction(function () use ($actor, $connectionId, $tenantId, $provenance, $retiredAt): ConnectionRetirementReport {
            $connection = ProviderConnection::query()
                ->forTenant($tenantId)
                ->whereKey($connectionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status === ProviderConnection::STATUS_RETIRED) {
                throw new ConnectionRetirementException(
                    "Provider connection {$connectionId} is already retired.",
                );
            }

            // Retiring underneath an open issue strands the operator: the queue
            // entry survives, and every route to acting on it — a resend, a
            // remap, another sync pass — has just been frozen.
            $open = ReconciliationIssue::query()
                ->forTenant($tenantId)
                ->where('connection_id', $connectionId)
                ->where('status', ReconciliationIssue::STATUS_OPEN)
                ->count();

            if ($open > 0) {
                throw new ConnectionRetirementException(
                    "Provider connection {$connectionId} still has {$open} open reconciliation issue(s); resolve them before retiring it.",
                );
            }

            $before = ['status' => $connection->status, 'provider_id' => $connection->provider_id, 'scope_key' => $connection->scope_key];

            $connection->fill([
                'status' => ProviderConnection::STATUS_RETIRED,
                'deactivated_at' => $retiredAt,
            ])->save();

            app(SchedulerPrincipalGrants::class)->revoke($connection);

            $this->audit->record(
                $actor,
                OperatorAuditOperation::ConnectionRetired,
                $connectionId,
                null,
                $provenance->reviewReference,
                $before,
                ['status' => ProviderConnection::STATUS_RETIRED, 'retired_at' => $retiredAt->format(DATE_ATOM), 'scheduler_grants_revoked' => true],
                $retiredAt,
            );

            return new ConnectionRetirementReport(
                connectionId: $connectionId,
                connection: $connection->refresh(),
                reviewReference: (string) $provenance->reviewReference,
                retiredAt: $retiredAt,
            );
        });
    }

    /**
     * Refuse a write that would add a new fact to a retired connection.
     *
     * Placed here rather than duplicated at each write site so the stores share
     * one definition of what retirement forbids.
     */
    public static function assertWritable(ProviderConnection $connection): void
    {
        if ($connection->status === ProviderConnection::STATUS_RETIRED) {
            throw new ConnectionRetirementException(
                "Provider connection {$connection->id} is retired; its checkpoints and projections are read-only history.",
            );
        }
    }
}
