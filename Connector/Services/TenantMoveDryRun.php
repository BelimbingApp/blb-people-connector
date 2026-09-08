<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\TenantMoveDryRunReport;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Reports what a full tenant move would touch, without writing (#240, plan
 * 1011): rows per connector-owned table in the source tenant, source
 * identities whose external id the target tenant already maps, webhook
 * deliveries still in flight and parked feed pages. Any of the last three
 * is a blocker.
 *
 * The operator must be authorized for the move in both tenants: the same
 * capability is asked of the authorization service under the source tenant
 * context and again under the target's, so a tenant's own operator set
 * decides for that tenant. Nothing is written, not even an audit row: a dry
 * run that changed a table would fail its own promise.
 */
final class TenantMoveDryRun
{
    public const MIGRATE_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
    ) {}

    public function report(Actor $actor, int $sourceTenantId, int $targetTenantId): TenantMoveDryRunReport
    {
        if ($sourceTenantId === $targetTenantId) {
            throw new InvalidProviderConfigurationException('The source and target tenants must differ.');
        }
        if ($actor->validate() !== null) {
            throw new ProviderAuthorizationException('connector', 'migrate_dry_run', 'A tenant move dry run runs as a valid operator.');
        }
        // Both tenants' operator sets must admit the operator: the decision is
        // asked once under each tenant context.
        foreach ([$sourceTenantId, $targetTenantId] as $tenantId) {
            $this->tenants->runForTenant($tenantId, fn () => $this->authorization->authorize($actor, self::MIGRATE_CAPABILITY));
        }

        $tables = [];
        foreach ($this->tables() as $table) {
            $tables[$table] = (int) DB::table($table)->where('tenant_id', $sourceTenantId)->count();
        }

        return new TenantMoveDryRunReport(
            $sourceTenantId,
            $targetTenantId,
            $tables,
            $this->collisions($sourceTenantId, $targetTenantId),
            // In flight = queued (accepted) or failed with a retry still pending:
            // the job marks `failed` and rethrows while attempts remain (#255).
            // Dead-lettered is terminal and is not counted.
            (int) WebhookDelivery::query()->forTenant($sourceTenantId)->whereIn('status', [WebhookDelivery::STATUS_ACCEPTED, WebhookDelivery::STATUS_FAILED])->count(),
            (int) ReconciliationIssue::query()->forTenant($sourceTenantId)
                ->where('kind', WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER)
                ->where('status', ReconciliationIssue::STATUS_OPEN)->count(),
        );
    }

    /**
     * Active source identities whose (resource type, external id) the target
     * already maps on any connection. Hashes, not external ids, are compared
     * and reported: the report is for an operator console, not a ledger.
     *
     * @return list<array{source_identity: int, target_identity: int, resource_type: string, external_id_hash: string}>
     */
    private function collisions(int $sourceTenantId, int $targetTenantId): array
    {
        $source = ExternalIdentity::query()->forTenant($sourceTenantId)
            ->where('state', 'active')->orderBy('id')
            ->get(['id', 'resource_type', 'external_id_hash']);
        if ($source->isEmpty()) {
            return [];
        }
        $target = ExternalIdentity::query()->forTenant($targetTenantId)
            ->where('state', 'active')
            ->whereIn('external_id_hash', $source->pluck('external_id_hash')->unique()->all())
            ->get(['id', 'resource_type', 'external_id_hash'])
            ->keyBy(fn (ExternalIdentity $i): string => $i->resource_type.':'.$i->external_id_hash);

        $collisions = [];
        foreach ($source as $identity) {
            $match = $target->get($identity->resource_type.':'.$identity->external_id_hash);
            if ($match !== null) {
                $collisions[] = ['source_identity' => (int) $identity->id, 'target_identity' => (int) $match->id, 'resource_type' => (string) $identity->resource_type, 'external_id_hash' => substr((string) $identity->external_id_hash, 0, 12)];
            }
        }

        return $collisions;
    }

    /** @return list<string> every connector-owned table, as the retention policy enumerates them */
    private function tables(): array
    {
        $retention = config('people-connector.retention', []);

        return array_values(array_filter(array_keys(is_array($retention) ? $retention : []), 'is_string'));
    }
}
