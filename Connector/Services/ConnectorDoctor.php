<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\ConnectorDoctorReport;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;
use Illuminate\Support\Facades\DB;

/** One fail-closed, tenant-scoped operator pass over connector health. */
final class ConnectorDoctor
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderRegistry $registry,
        private readonly ProviderPortResolver $ports,
        private readonly SchedulerPrincipal $principals,
    ) {}

    public function inspect(Actor $actor): ConnectorDoctorReport
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorizeOperator($actor, $tenantId);

        [$adapterCount, $adapterViolations] = $this->adapterConformance($tenantId);
        [$stale, $staleDetail] = $this->staleWebhookDeliveries($tenantId);
        $drift = ReconciliationIssue::query()->forTenant($tenantId)->where('status', ReconciliationIssue::STATUS_OPEN)->count();
        $unresolved = $this->unresolvedMappings($tenantId);

        return new ConnectorDoctorReport([
            $this->row('adapter_conformance', count($adapterViolations), count($adapterViolations).' violations across '.$adapterCount.' configured'.($adapterViolations === [] ? '' : ': '.implode(', ', $adapterViolations))),
            $this->row('webhook_deliveries', $stale, $staleDetail),
            $this->row('reconciliation_drift', $drift, "{$drift} open"),
            $this->row('identity_mappings', $unresolved, "{$unresolved} unresolved"),
        ]);
    }

    public function record(Actor $actor, ?\DateTimeImmutable $measuredAt = null): ConnectorDoctorReport
    {
        $report = $this->inspect($actor);
        $tenantId = $this->tenants->requireTenantId();
        $measuredAt ??= \DateTimeImmutable::createFromInterface(now());

        DB::table('people_connector_connector_doctor_snapshots')->insert(array_map(
            static fn (array $row): array => [
                'tenant_id' => $tenantId,
                'check' => $row['check'],
                'status' => $row['status'],
                'count' => $row['count'],
                'measured_at' => $measuredAt,
            ],
            $report->checks,
        ));

        return $report;
    }

    /** @return list<array{check: string, status: string, count: int, measured_at: string}> */
    public function history(Actor $actor, int $days, ?\DateTimeImmutable $now = null): array
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorizeOperator($actor, $tenantId);

        if ($days < 1) {
            throw new \InvalidArgumentException('Connector doctor history days must be at least one.');
        }

        $now ??= \DateTimeImmutable::createFromInterface(now());

        return DB::table('people_connector_connector_doctor_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('measured_at', '>=', $now->modify("-{$days} days"))
            ->orderBy('check')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get(['check', 'status', 'count', 'measured_at'])
            ->unique('check')
            ->map(static fn (object $row): array => [
                'check' => (string) $row->check,
                'status' => (string) $row->status,
                'count' => (int) $row->count,
                'measured_at' => (string) $row->measured_at,
            ])
            ->values()
            ->all();
    }

    /** @return array{int, list<string>} */
    private function adapterConformance(int $tenantId): array
    {
        $connections = ProviderConnection::query()->forTenant($tenantId)
            ->orderByRaw('case when status = ? then 0 else 1 end', [ProviderConnection::STATUS_ACTIVE])
            ->orderBy('id')
            ->get()
            ->unique('provider_id');
        $violations = [];
        foreach ($connections as $connection) {
            if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
                $violations[] = 'adapter_not_active:'.$connection->provider_id;

                continue;
            }
            $provider = $this->registry->find((string) $connection->provider_id);
            if ($provider === null) {
                $violations[] = 'adapter_not_registered:'.$connection->provider_id;

                continue;
            }
            $scope = $connection->company_id === null
                ? ProviderScope::tenant()
                : ProviderScope::company((int) $connection->company_id);
            $principal = $this->principals->forConnection($connection);
            $violations = [...$violations, ...ProviderConformance::violations(
                $provider,
                resolvePort: fn (PeopleCapability $capability, string $contract): object => is_a($contract, ReadableProviderPort::class, true)
                    ? $this->ports->read($principal, $provider, $capability, $contract, $scope)
                    : $this->ports->write($principal, $provider, $capability, $contract, $scope),
            )];
        }

        return [$connections->count(), $violations];
    }

    /** @return array{int, string} */
    private function staleWebhookDeliveries(int $tenantId): array
    {
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return [1, 'queue backend is not inspectable'];
        }
        $table = config("queue.connections.{$connection}.table", 'jobs');
        $rows = DB::table($table)->where('queue', RunIncrementalWorkforceSync::QUEUE)
            ->where('created_at', '<', now()->subHour()->timestamp)->pluck('payload');

        $stale = $rows->filter(fn (string $payload): bool => $this->queuedTenant($payload) === $tenantId)->count();

        return [$stale, "{$stale} stale"];
    }

    private function queuedTenant(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            return null;
        }
        $command = $decoded['data']['command'] ?? null;
        if (($decoded['displayName'] ?? null) !== RunIncrementalWorkforceSync::class || ! is_string($command)) {
            return null;
        }
        $job = @unserialize($command, ['allowed_classes' => [RunIncrementalWorkforceSync::class]]);

        return $job instanceof RunIncrementalWorkforceSync ? $job->tenantId : null;
    }

    private function unresolvedMappings(int $tenantId): int
    {
        $identities = (new ExternalIdentity)->getTable();
        $entities = (new WorkforceEntity)->getTable();
        $connections = (new ProviderConnection)->getTable();

        return DB::table("{$identities} as identity")
            ->leftJoin("{$entities} as entity", fn ($join) => $join->on('entity.id', 'identity.workforce_entity_id')->on('entity.tenant_id', 'identity.tenant_id'))
            ->leftJoin("{$connections} as connection", fn ($join) => $join->on('connection.id', 'identity.connection_id')->on('connection.tenant_id', 'identity.tenant_id'))
            ->where('identity.tenant_id', $tenantId)
            ->where('identity.state', ExternalIdentity::STATE_ACTIVE)
            ->where(fn ($query) => $query->whereNull('entity.id')->orWhereNull('connection.id')
                ->orWhereColumn('entity.resource_type', '!=', 'identity.resource_type')
                ->orWhereColumn('connection.provider_id', '!=', 'identity.provider_id')
                ->orWhere('entity.state', '!=', WorkforceEntity::STATE_ACTIVE))
            ->count();
    }

    /** @return array{check: string, status: string, count: int, detail: string} */
    private function row(string $check, int $failures, string $detail): array
    {
        return ['check' => $check, 'status' => $failures === 0 ? 'green' : 'red', 'count' => $failures, 'detail' => $detail];
    }

    private function authorizeOperator(Actor $actor, int $tenantId): void
    {
        $this->authorization->authorize($actor, ConnectorHealthService::READ_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'doctor', 'Connector doctor requires an operator inside the current tenant.');
        }
    }
}
