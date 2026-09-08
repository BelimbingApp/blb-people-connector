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
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
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
        private readonly WebhookReceiptLedger $receipts,
        private readonly WorkforceFreshnessPolicy $freshness,
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
            // A delivery whose retry budget ended left the queue, so the row
            // above gets greener as it fails (#271); this one counts it until
            // an operator replays it. Parked pages are the subset of open
            // issues that means a stuck feed, counted apart from the total.
            $this->webhookDeadLetters($tenantId),
            $this->syncDeadLetters($tenantId),
            $this->row('identity_mappings', $unresolved, "{$unresolved} unresolved"),
            // A receipt with no delivery behind it is a reservation whose enqueue
            // never ran; its retry is acknowledged as a duplicate, so this row is
            // the only place the lost sync shows (#227).
            $this->row('webhook_stuck_reservations', $stuck = $this->receipts->stuckReservations($tenantId), "{$stuck} stuck"),
            // Informational (#227): a duplicate acknowledged is a retry that did
            // no harm, so the row never turns the doctor red.
            ['check' => 'webhook_duplicates', 'status' => 'green', 'count' => $duplicates = $this->receipts->duplicatesSkipped($tenantId), 'detail' => "{$duplicates} skipped in 7 days"],
            // Yellow while a previous signing secret is still inside its
            // rotation overlap (#247): expected during a rotation, worth
            // noticing if it never clears.
            $this->secretOverlap($tenantId),
            // The delegation signing key is deployment-wide, so every tenant
            // is told the same thing here (#262). It is on the doctor because
            // a rotation nobody finished is invisible until tokens minted
            // before it start being refused.
            $this->delegationSecretOverlap(),
            // Yellow while a connection is inside a planned maintenance window
            // (#264): the pause is an operator's decision, not a fault.
            $this->maintenance($tenantId),
            // One row per active connection (#296): the only failure knowable
            // weeks ahead, so it gets a warning window and its own alert key.
            ...$this->credentialExpiry($tenantId),
            // One row per active connection (#284): the durable checkpoint is
            // the only evidence a feed is still moving, so a connection that
            // stopped synchronizing is red here and reaches --alert.
            ...$this->workforceFreshness($tenantId),
        ]);
    }

    /**
     * Provider credential expiry, one row per active connection of the tenant.
     *
     * Red when no usable credential exists right now (expired, revoked, or
     * never issued), yellow when the latest usable one expires inside
     * `people-connector.doctor.credential_warning_days`, green otherwise. The
     * detail names the credential id, key id and expiry and never the secret
     * reference (docs/contracts/diagnostic-privacy.md). Inactive and retired
     * connections have no row: nothing authenticates as them.
     *
     * @return list<array{check: string, status: string, count: int, detail: string}>
     */
    public function credentialExpiry(int $tenantId): array
    {
        $now = \DateTimeImmutable::createFromInterface(now());
        $warning = $now->modify('+'.self::credentialWarningDays().' days');
        $rows = [];

        $connectionIds = ProviderConnection::query()->forTenant($tenantId)
            ->where('status', ProviderConnection::STATUS_ACTIVE)
            ->orderBy('id')
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            $check = 'provider_credential_expiry:'.(int) $connectionId;
            $credential = ProviderCredentialRecord::query()
                ->forTenant($tenantId)
                ->where('connection_id', (int) $connectionId)
                ->whereNull('revoked_at')
                ->where('issued_at', '<=', $now)
                ->where('expires_at', '>', $now)
                ->orderByDesc('expires_at')
                ->orderByDesc('id')
                ->first(['credential_id', 'key_id', 'expires_at']);

            if ($credential === null) {
                $rows[] = ['check' => $check, 'status' => 'red', 'count' => 1, 'detail' => 'no usable credential'];

                continue;
            }

            $expiresAt = \DateTimeImmutable::createFromInterface($credential->expires_at);
            $detail = "credential {$credential->credential_id} key {$credential->key_id} expires ".$expiresAt->format(DATE_ATOM);

            $rows[] = $expiresAt <= $warning
                ? ['check' => $check, 'status' => 'yellow', 'count' => 1, 'detail' => $detail.' (inside the '.self::credentialWarningDays().'-day warning window)']
                : ['check' => $check, 'status' => 'green', 'count' => 0, 'detail' => $detail];
        }

        return $rows;
    }

    public static function credentialWarningDays(): int
    {
        $days = config('people-connector.doctor.credential_warning_days');

        return is_int($days) && $days >= 0 ? $days : 14;
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

    /**
     * Dead-lettered deliveries nobody has replayed yet. A replay keeps the
     * original row and points at it through `replayed_from_id`, so the
     * count drops when the replay is created, not when it completes: the
     * new pass is watched by the queue-based `webhook_deliveries` row.
     *
     * @return array{check: string, status: string, count: int, detail: string}
     */
    private function webhookDeadLetters(int $tenantId): array
    {
        $deliveries = (new WebhookDelivery)->getTable();
        $unreplayed = WebhookDelivery::query()->forTenant($tenantId)
            ->where('status', WebhookDelivery::STATUS_DEAD_LETTERED)
            ->whereNotExists(fn ($query) => $query->from("{$deliveries} as replay")
                ->whereColumn('replay.replayed_from_id', "{$deliveries}.id")
                ->whereColumn('replay.tenant_id', "{$deliveries}.tenant_id"));
        $count = (clone $unreplayed)->count();
        $oldest = $count === 0 ? null : $unreplayed->orderBy('failed_at')->orderBy('id')->first()?->failed_at;

        return $this->row('webhook_dead_letters', $count, $count === 0
            ? '0 dead-lettered'
            : "{$count} dead-lettered, oldest failed_at ".($oldest?->format(DATE_ATOM) ?? 'unknown'));
    }

    /** @return array{check: string, status: string, count: int, detail: string} */
    private function syncDeadLetters(int $tenantId): array
    {
        $parked = ReconciliationIssue::query()->forTenant($tenantId)
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->where('kind', WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER);
        $count = (clone $parked)->count();
        $connections = $count === 0 ? 0 : (clone $parked)->distinct()->count('connection_id');

        return $this->row('sync_dead_letters', $count, "{$count} parked pages across {$connections} connections");
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
    /** @return array{check: string, status: string, count: int, detail: string} */
    private function secretOverlap(int $tenantId): array
    {
        $now = \DateTimeImmutable::createFromInterface(now());
        $earliest = null;
        $count = 0;
        foreach (ProviderConnection::query()->forTenant($tenantId)->orderBy('id')->pluck('id') as $connectionId) {
            $endsAt = WebhookSecrets::overlapEndsAt((int) $connectionId, $now);
            if ($endsAt === null) {
                continue;
            }
            $count++;
            $earliest = $earliest === null || $endsAt < $earliest ? $endsAt : $earliest;
        }

        return [
            'check' => 'webhook_secret_overlap',
            'status' => $count === 0 ? 'green' : 'yellow',
            'count' => $count,
            'detail' => $count === 0 ? '0 overlapping' : "{$count} overlapping, earliest expiry {$earliest?->format(DATE_ATOM)}",
        ];
    }

    /**
     * The rotation overlap on the deployment-wide delegated-authority secret.
     *
     * Yellow is a rotation in progress and does not fail the doctor; red is a
     * previous secret that will never be accepted, which means either the
     * operator never set an expiry or the window has closed and the key was
     * left behind. The detail carries the expiry and never the secret, here
     * as everywhere else: docs/contracts/diagnostic-privacy.md.
     *
     * @return array{check: string, status: string, count: int, detail: string}
     */
    private function delegationSecretOverlap(): array
    {
        $row = static fn (string $status, int $count, string $detail): array => [
            'check' => 'delegation_secret_overlap', 'status' => $status, 'count' => $count, 'detail' => $detail,
        ];

        if (! DelegationPolicy::previousSecretConfigured()) {
            return $row('green', 0, 'no previous delegation secret');
        }

        if (DelegationPolicy::previousSecret() === null) {
            return $row('red', 1, 'previous delegation secret is shorter than '.DelegatedAuthoritySigner::MINIMUM_SECRET_BYTES.' bytes and is never accepted');
        }

        if (($expiresAt = DelegationPolicy::previousSecretExpiresAt()) === null) {
            return $row('red', 1, 'previous delegation secret has no readable expiry');
        }

        return DelegationPolicy::previousSecretAcceptedAt(\DateTimeImmutable::createFromInterface(now()))
            ? $row('yellow', 1, 'previous delegation secret accepted until '.$expiresAt->format(DATE_ATOM))
            : $row('red', 1, 'previous delegation secret lapsed at '.$expiresAt->format(DATE_ATOM));
    }

    /** @return array{check: string, status: string, count: int, detail: string} */
    private function maintenance(int $tenantId): array
    {
        $windows = ProviderConnection::query()->forTenant($tenantId)
            ->where('maintenance_until', '>', now())
            ->orderByDesc('maintenance_until')
            ->get(['id', 'maintenance_until']);
        $count = $windows->count();
        $latest = $windows->first()?->maintenance_until;

        return [
            'check' => 'connection_maintenance',
            'status' => $count === 0 ? 'green' : 'yellow',
            'count' => $count,
            'detail' => $count === 0 ? '0 in maintenance' : "{$count} in maintenance, latest window ends {$latest->format(DATE_ATOM)}",
        ];
    }

    /**
     * Workforce freshness per active connection, keyed `workforce_freshness:<id>`.
     *
     * Red carries the policy's reason code and the age; green the age alone.
     * Inactive and retired connections produce no row: their staleness is a
     * decision already taken, not a fault the doctor should page on.
     *
     * @return list<array{check: string, status: string, count: int, detail: string}>
     */
    private function workforceFreshness(int $tenantId): array
    {
        $now = \DateTimeImmutable::createFromInterface(now());
        $rows = [];
        foreach (ProviderConnection::query()->forTenant($tenantId)->where('status', ProviderConnection::STATUS_ACTIVE)->orderBy('id')->pluck('id') as $connectionId) {
            $freshness = $this->freshness->for((int) $connectionId, $now);
            $age = $freshness->ageMinutes() === null ? null : "{$freshness->ageMinutes()} minutes old, maximum {$freshness->maxAgeMinutes}";
            $rows[] = $this->row(
                "workforce_freshness:{$connectionId}",
                $freshness->isStale() ? 1 : 0,
                $freshness->isStale() ? $freshness->staleReason.($age === null ? '' : ", {$age}") : (string) $age,
            );
        }

        return $rows;
    }

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
