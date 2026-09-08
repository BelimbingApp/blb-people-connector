<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\SupportBundle;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * One privacy-safe diagnostics zip per tenant (#250, plan 1012).
 *
 * Every file is built from counts, outcomes, timestamps and config shape;
 * nothing carries a payload, a subject identifier or a secret. Sources that
 * could carry text (sync run summaries, config) pass through the redactor,
 * and the manifest names every rule that fired so support knows what was
 * removed. Authorized like the doctor, tenant-scoped, one audit row per
 * bundle, refused before any file exists.
 */
final class SupportBundleBuilder
{
    public const READ_CAPABILITY = ConnectorHealthService::READ_CAPABILITY;

    /** Sync-pass summary keys allowed into the bundle: counts, timings and outcomes only. */
    private const SYNC_RUN_KEYS = ['stream', 'pass', 'pages', 'upserts', 'deactivations', 'refusals', 'duration_ms', 'completed'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderRegistry $registry,
        private readonly RetentionPolicy $retention,
        private readonly WebhookReceiptLedger $receipts,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function build(Actor $actor, DateTimeImmutable $since, ?string $outDir = null): SupportBundle
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, self::READ_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'support_bundle', 'A support bundle describes one tenant and requires an operator inside it.');
        }
        $now = DateTimeImmutable::createFromInterface(now());
        if ($since >= $now) {
            throw new InvalidProviderConfigurationException('The bundle window must start in the past.');
        }

        $redactor = new SupportBundleRedactor;
        $files = [
            'doctor-history.json' => $this->doctorHistory($tenantId, $since),
            'sync-runs.json' => $this->syncRuns($tenantId, $since, $redactor),
            'webhooks.json' => $this->webhooks($tenantId, $since),
            'reconciliation.json' => $this->reconciliation($tenantId),
            'retention.json' => $this->retention($actor),
            'versions.json' => $this->versions(),
            'config.json' => $redactor->redact(config('people-connector', [])),
        ];
        $manifest = [
            'tenant' => $tenantId,
            'window' => ['since' => $since->format(DATE_ATOM), 'until' => $now->format(DATE_ATOM)],
            'generated_at' => $now->format(DATE_ATOM),
            'generated_by' => "{$actor->type->value}:{$actor->id}",
            'redaction_rules_available' => SupportBundleRedactor::RULES,
            'redaction_rules_applied' => $redactor->fired(),
            'files' => array_keys($files),
        ];

        $dir = $outDir ?? storage_path('app/people-connector/support');
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new InvalidProviderConfigurationException("The bundle directory [{$dir}] cannot be created.");
        }
        $path = rtrim($dir, '/').'/support-bundle-'.$tenantId.'-'.$now->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InvalidProviderConfigurationException("The bundle [{$path}] cannot be written.");
        }
        foreach ($files + ['manifest.json' => $manifest] as $name => $content) {
            $zip->addFromString($name, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        $zip->close();
        $bytes = (int) filesize($path);

        $this->audit->record(
            $actor,
            OperatorAuditOperation::SupportBundled,
            null,
            null,
            basename($path),
            ['since' => $since->format(DATE_ATOM)],
            ['files' => count($files) + 1, 'bytes' => $bytes, 'redaction_rules_applied' => $redactor->fired()],
        );

        return new SupportBundle($path, $bytes, $manifest);
    }

    /** @return list<array{check: string, status: string, count: int, measured_at: string}> */
    private function doctorHistory(int $tenantId, DateTimeImmutable $since): array
    {
        return DB::table('people_connector_connector_doctor_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('measured_at', '>=', $since)
            ->orderByDesc('measured_at')->orderBy('check')
            ->get(['check', 'status', 'count', 'measured_at'])
            ->map(fn (object $row): array => ['check' => (string) $row->check, 'status' => (string) $row->status, 'count' => (int) $row->count, 'measured_at' => (string) $row->measured_at])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function syncRuns(int $tenantId, DateTimeImmutable $since, SupportBundleRedactor $redactor): array
    {
        return OperatorAudit::query()->forTenant($tenantId)
            ->where('operation', OperatorAuditOperation::SyncPass->value)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->get()
            ->map(function (OperatorAudit $row) use ($redactor): array {
                $summary = array_intersect_key(($row->before_summary ?? []) + ($row->after_summary ?? []), array_flip(self::SYNC_RUN_KEYS));

                return ['connection' => $row->connection_id, 'occurred_at' => $row->occurred_at?->format(DATE_ATOM)] + $redactor->redact($summary);
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function webhooks(int $tenantId, DateTimeImmutable $since): array
    {
        $byStatus = WebhookDelivery::query()->forTenant($tenantId)->where('received_at', '>=', $since)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn (mixed $n): int => (int) $n)->all();

        return [
            'deliveries_by_status' => $byStatus,
            'dead_lettered_deliveries' => $byStatus[WebhookDelivery::STATUS_DEAD_LETTERED] ?? 0,
            'receipts' => (int) WebhookReceipt::query()->forTenant($tenantId)->where('first_seen_at', '>=', $since)->count(),
            'duplicates_skipped' => $this->receipts->duplicatesSkipped($tenantId, max(1, (int) ceil(($since->diff(now())->days ?: 1)))),
            'stuck_reservations' => $this->receipts->stuckReservations($tenantId),
            'parked_pages_open' => (int) ReconciliationIssue::query()->forTenant($tenantId)
                ->where('kind', WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER)->where('status', ReconciliationIssue::STATUS_OPEN)->count(),
        ];
    }

    /** @return array{open_by_kind: array<string, int>} */
    private function reconciliation(int $tenantId): array
    {
        return ['open_by_kind' => ReconciliationIssue::query()->forTenant($tenantId)
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->orderBy('kind')->pluck('n', 'kind')->map(fn (mixed $n): int => (int) $n)->all()];
    }

    /** @return array<string, mixed> */
    private function retention(Actor $actor): array
    {
        try {
            $report = $this->retention->review($actor);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException) {
            return ['skipped' => 'the operator lacks the retention review capability'];
        }
        $tables = [];
        foreach ($report->tables as $table => $row) {
            $tables[$table] = ['days' => $row->days, 'column' => $row->column, 'expired' => $row->expired];
        }

        return ['reviewed_at' => $report->reviewedAt->format(DATE_ATOM), 'tables' => $tables];
    }

    /** @return array<string, mixed> */
    private function versions(): array
    {
        $composer = json_decode((string) @file_get_contents(__DIR__.'/../composer.json'), true);
        $adapters = [];
        foreach ($this->registry->all() as $adapter) {
            $d = $adapter->descriptor();
            $adapters[$d->id] = ['name' => $d->name, 'adapter_version' => $d->adapterVersion, 'contract_version' => $d->contractVersion];
        }

        return [
            'connector' => is_array($composer) ? ($composer['version'] ?? null) : null,
            'configured_provider' => $this->registry->configuredProviderId(),
            'adapters' => $adapters,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ];
    }
}
