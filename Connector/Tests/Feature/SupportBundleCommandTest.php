<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SupportBundleRedactor;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * connector:support:bundle (#250): one zip per tenant, nothing of another
 * tenant, no secret or personal value, every fired rule in the manifest.
 * Self-contained: helpers are prefixed bundle.
 */
const BUNDLE_CORPUS = [
    'credential' => 'AKIAIOSFODNN7EXAMPLE',
    'token' => 'ghp_16C7e42F292c6912E7710c838347Ae178B4a',
    'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7\n-----END PRIVATE KEY-----",
    'email' => 'siti.rahman@example.com.my',
    'national_id' => '900101-14-5678',
    'account_number' => '112233445566',
];

function bundleAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException(providerId: 'connector', operation: 'test', message: 'The actor lacks the capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

beforeEach(fn () => bundleAuthz(true));

afterEach(function (): void {
    app(TenantContext::class)->clear();
    config()->set('people-connector.webhook.secrets', []);
    config()->set('people-connector.support_bundle_probe', null);
    foreach (glob(sys_get_temp_dir().'/bundle-test-*') ?: [] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
});

/** @return array{tenantId: int, operator: User, connection: int, actor: Actor} */
function bundleTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), 'test.bundle')->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    return ['tenantId' => (int) $tenant->id, 'operator' => $operator, 'connection' => (int) $connection->id, 'actor' => Actor::forUser($operator)];
}

/** Doctor snapshots, sync-pass audits, deliveries, receipts and issues for one tenant, dated relative to now. */
function bundleSeed(array $t, string $label): void
{
    app(TenantContext::class)->set($t['tenantId']);
    DB::table('people_connector_connector_doctor_snapshots')->insert([
        ['tenant_id' => $t['tenantId'], 'check' => 'probe_'.$label.'_inside', 'status' => 'green', 'count' => 0, 'measured_at' => now()->subDays(6)],
        ['tenant_id' => $t['tenantId'], 'check' => 'probe_'.$label.'_edge', 'status' => 'green', 'count' => 0, 'measured_at' => now()->subDays(7)],
        ['tenant_id' => $t['tenantId'], 'check' => 'probe_'.$label.'_outside', 'status' => 'red', 'count' => 1, 'measured_at' => now()->subDays(7)->subSecond()],
    ]);
    $audit = app(OperatorAuditLog::class);
    $audit->record($t['actor'], OperatorAuditOperation::SyncPass, $t['connection'], null, 'run-'.$label.'-inside',
        ['stream' => 'workforce', 'pass' => 'incremental', 'pages' => 2, 'upserts' => 5, 'deactivations' => 0, 'refusals' => 0, 'duration_ms' => 120, 'completed' => true, 'cursor' => 'CURSOR-'.$label.'-OPAQUE'], [], now()->subDays(1));
    $audit->record($t['actor'], OperatorAuditOperation::SyncPass, $t['connection'], null, 'run-'.$label.'-outside',
        ['stream' => 'workforce', 'pass' => 'bootstrap', 'pages' => 9, 'upserts' => 99, 'deactivations' => 0, 'refusals' => 0, 'duration_ms' => 999, 'completed' => true], [], now()->subDays(7)->subSecond());
    foreach (['accepted', 'delivered', 'dead_lettered'] as $status) {
        WebhookDelivery::query()->create(['tenant_id' => $t['tenantId'], 'connection_id' => $t['connection'], 'delivery_id' => 'd-'.$label.'-'.$status, 'status' => $status, 'received_at' => now()->subHour()]);
    }
    WebhookReceipt::query()->create(['tenant_id' => $t['tenantId'], 'provider_id' => 'test.bundle', 'connection_id' => $t['connection'], 'delivery_id' => 'd-'.$label.'-delivered', 'first_seen_at' => now()->subHour(), 'duplicate_count' => 2]);
    app(ReconciliationIssueStore::class)->report($t['connection'], 'sync:page:'.$label, WorkforceSyncRunner::ISSUE_KIND_DEAD_LETTER, new ReconciliationIssueDetails(reasonCode: 'page_refused'), WorkforceResourceType::Employee->value, 'PAGE-'.$label);
    app(ReconciliationIssueStore::class)->report($t['connection'], 'sync:employee:'.$label, 'sync_conflict', new ReconciliationIssueDetails(reasonCode: 'review_required'), WorkforceResourceType::Employee->value, 'EMP-'.$label.'-SUBJECT');
}

/** @return array<string, mixed> file name => decoded JSON */
function bundleRead(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $files[$name] = json_decode((string) $zip->getFromIndex($i), true, flags: JSON_THROW_ON_ERROR);
    }
    $zip->close();

    return $files;
}

function bundleRun(array $t, array $extra = []): array
{
    $dir = sys_get_temp_dir().'/bundle-test-'.uniqid();
    mkdir($dir);
    $exit = Artisan::call('connector:support:bundle', ['--tenant' => $t['tenantId'], '--as' => $t['operator']->id, '--out' => $dir, ...$extra]);

    return [$exit, Artisan::output(), glob($dir.'/*.zip') ?: []];
}

test('the bundle holds only this tenant, bounds the window to the second, and audits once', function (): void {
    $a = bundleTenant('Bundle Tenant A');
    $b = bundleTenant('Bundle Tenant B');
    bundleSeed($a, 'a');
    bundleSeed($b, 'b');
    $audits = OperatorAudit::query()->count();

    [$exit, $output, $zips] = bundleRun($a);
    expect($exit)->toBe(0)->and($zips)->toHaveCount(1)
        ->and($output)->toContain('Wrote '.$zips[0], ' bytes)');
    $files = bundleRead($zips[0]);
    expect(array_keys($files))->toBe(['doctor-history.json', 'sync-runs.json', 'webhooks.json', 'reconciliation.json', 'retention.json', 'versions.json', 'config.json', 'manifest.json']);

    // One needle per assertion: a negated toContain with several needles
    // fails only when all of them are present.
    $checks = array_column($files['doctor-history.json'], 'check');
    expect($checks)->toContain('probe_a_inside')->toContain('probe_a_edge');
    foreach (['probe_a_outside', 'probe_b_inside', 'probe_b_edge'] as $absent) {
        expect($checks)->not->toContain($absent);
    }
    expect(array_column($files['sync-runs.json'], 'connection'))->toBe([$a['connection']])
        ->and($files['sync-runs.json'][0])->toMatchArray(['pass' => 'incremental', 'pages' => 2, 'upserts' => 5, 'duration_ms' => 120, 'completed' => true])
        ->and($files['sync-runs.json'][0])->not->toHaveKey('cursor');
    foreach (['run-a-inside', 'bootstrap', 'CURSOR-a-OPAQUE'] as $absent) {
        expect(json_encode($files['sync-runs.json']))->not->toContain($absent);
    }
    expect($files['webhooks.json']['deliveries_by_status'])->toBe(['accepted' => 1, 'dead_lettered' => 1, 'delivered' => 1])
        ->and($files['webhooks.json']['dead_lettered_deliveries'])->toBe(1)
        ->and($files['webhooks.json']['receipts'])->toBe(1)
        ->and($files['webhooks.json']['duplicates_skipped'])->toBe(2)
        ->and($files['webhooks.json']['parked_pages_open'])->toBe(1);
    expect($files['reconciliation.json']['open_by_kind'])->toBe(['sync_conflict' => 1, 'sync_dead_letter' => 1]);
    foreach (['EMP-a-SUBJECT', 'EMP-b-SUBJECT', 'PAGE-a', 'CURSOR-b-OPAQUE', '"connection": '.$b['connection'].','] as $absent) {
        expect(json_encode($files, JSON_PRETTY_PRINT))->not->toContain($absent);
    }
    expect($files['versions.json']['php'])->toBe(PHP_VERSION)
        ->and($files['manifest.json']['tenant'])->toBe($a['tenantId'])
        ->and($files['manifest.json']['files'])->toHaveCount(7);

    $audit = OperatorAudit::query()->forTenant($a['tenantId'])->where('operation', OperatorAuditOperation::SupportBundled->value)->sole();
    expect(OperatorAudit::query()->count())->toBe($audits + 1)
        ->and($audit->review_reference)->toBe(basename($zips[0]))
        ->and($audit->after_summary['bytes'])->toBe(filesize($zips[0]))
        ->and($audit->actor_id)->toBe((int) $a['operator']->id);
});

test('no corpus value leaves the tenant and every fingerprint and fired rule is in the bundle', function (): void {
    $a = bundleTenant('Bundle Tenant A');
    bundleSeed($a, 'a');
    config()->set("people-connector.webhook.secrets.{$a['connection']}", [['secret' => BUNDLE_CORPUS['token']], ['secret' => BUNDLE_CORPUS['credential'], 'expires_at' => '2026-09-08T00:00:00+00:00']]);
    config()->set('people-connector.support_bundle_probe', [
        // Under a plain key so the PEM pattern rule, not the key rule, must catch it.
        'notes' => BUNDLE_CORPUS['private_key'],
        'contact' => 'Escalate to '.BUNDLE_CORPUS['email'].' quoting id '.BUNDLE_CORPUS['national_id'].' or account '.BUNDLE_CORPUS['account_number'],
    ]);
    app(OperatorAuditLog::class)->record($a['actor'], OperatorAuditOperation::SyncPass, $a['connection'], null, 'run-corpus',
        ['stream' => 'workforce', 'pass' => 'incremental', 'pages' => 1, 'upserts' => 1, 'deactivations' => 0, 'refusals' => 0, 'duration_ms' => 1, 'completed' => true], [], now()->subHour());

    [$exit, , $zips] = bundleRun($a);
    expect($exit)->toBe(0);
    $zip = new ZipArchive;
    $zip->open($zips[0]);
    $raw = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $raw .= $zip->getFromIndex($i);
    }
    $zip->close();

    foreach (BUNDLE_CORPUS as $name => $value) {
        expect($raw)->not->toContain($value)
            ->and($raw)->toContain(SupportBundleRedactor::fingerprint($value));
    }
    $files = bundleRead($zips[0]);
    expect($files['config.json']['webhook']['secrets'][(string) $a['connection']][1])->toBe(['secret' => SupportBundleRedactor::fingerprint(BUNDLE_CORPUS['credential']), 'expires_at' => '2026-09-08T00:00:00+00:00'])
        ->and($files['manifest.json']['redaction_rules_applied'])->toBe(['secret_key', 'private_key', 'email', 'id_number']);
});

test('an unauthorized operator and an operator of another tenant are refused with no file and no audit row', function (): void {
    $a = bundleTenant('Bundle Tenant A');
    $b = bundleTenant('Bundle Tenant B');
    bundleAuthz(false);
    [$exit, $output, $zips] = bundleRun($a);
    expect($exit)->toBe(1)->and($output)->toContain('lacks the capability')->and($zips)->toBe([]);

    bundleAuthz(true);
    $dir = sys_get_temp_dir().'/bundle-test-'.uniqid();
    mkdir($dir);
    expect(Artisan::call('connector:support:bundle', ['--tenant' => $a['tenantId'], '--as' => $b['operator']->id, '--out' => $dir]))->toBe(1)
        ->and(Artisan::output())->toContain('operator inside it')
        ->and(glob($dir.'/*'))->toBe([]);
    expect(Artisan::call('connector:support:bundle', ['--tenant' => $a['tenantId'], '--as' => $a['operator']->id, '--out' => $dir, '--since' => 'lately']))->toBe(1)
        ->and(Artisan::output())->toContain('--since takes');
    expect(OperatorAudit::query()->where('operation', OperatorAuditOperation::SupportBundled->value)->count())->toBe(0);
});

test('--since in hours bounds the doctor history and sync runs to the instant', function (): void {
    $a = bundleTenant('Bundle Tenant A');
    bundleSeed($a, 'a');
    DB::table('people_connector_connector_doctor_snapshots')->insert(['tenant_id' => $a['tenantId'], 'check' => 'probe_a_recent', 'status' => 'green', 'count' => 0, 'measured_at' => now()->subHours(2)]);

    [$exit, , $zips] = bundleRun($a, ['--since' => '3h']);
    expect($exit)->toBe(0);
    $files = bundleRead($zips[0]);
    expect(array_column($files['doctor-history.json'], 'check'))->toBe(['probe_a_recent'])
        ->and($files['sync-runs.json'])->toBe([])
        ->and($files['manifest.json']['window']['since'])->toBe(now()->subHours(3)->format(DATE_ATOM));
});
