<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionResidueException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use App\Domains\PeopleConnector\Connector\Models\WebhookDelivery;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use App\Domains\PeopleConnector\Connector\Services\ConnectionResidueReporter;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\FileExchangeLedger;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderCredentialStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed residue and lives here.
 */

const RESIDUE_PROVIDER = 'test.residue';

const RESIDUE_REPLACEMENT = 'test.residue-replacement';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function residueAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                // Match the platform AuthorizationEngine path: deny throws
                // AuthorizationDeniedException, not a connector-local type.
                throw new \App\Base\Authz\Exceptions\AuthorizationDeniedException(
                    AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY),
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

function residueRef(string $provider, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($provider, $type, $id);
}

/**
 * A retired connection seeded with the residue the acceptance asks for.
 *
 * @return array{
 *     tenantId: int,
 *     companyId: int,
 *     connectionId: int,
 *     replacementId: int,
 *     actor: Actor,
 *     credentialId: string,
 *     issueId: int,
 *     connection: ProviderConnection
 * }
 */
function residueRetiredFixture(string $name): array
{
    residueAuthz(true);
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company($companyId);

    $connection = $store->activate((int) $store->configure($scope, RESIDUE_PROVIDER)->id);
    $connectionId = (int) $connection->id;

    $actor = new Actor(PrincipalType::USER, 8101, $companyId, tenantId: $tenantId);
    $at = new DateTimeImmutable('2026-09-07T08:00:00+00:00');
    $companyRef = residueRef(RESIDUE_PROVIDER, WorkforceResourceType::Company, 'RES-CO');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($connectionId, new WorkforceCompany($companyRef, 'Residue Co', true, $at));

    foreach (['RES-EMP-1', 'RES-EMP-2'] as $externalId) {
        $projections->upsert($connectionId, new WorkforceEmployee(
            reference: residueRef(RESIDUE_PROVIDER, WorkforceResourceType::Employee, $externalId),
            companyReference: $companyRef,
            displayName: 'Person '.$externalId,
            active: true,
            effectiveAt: $at,
            observedAt: $at,
        ));
    }

    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $connectionId,
        'workforce',
        new WorkforceChangePage(
            changes: [],
            asOf: $at,
            resumeCursor: 'cursor-1',
            complete: true,
        ),
        expectedVersion: 0,
        completedAt: $at,
    );

    $receivedAt = $at->modify('+1 hour');
    foreach (['d-1', 'd-2', 'd-3'] as $deliveryId) {
        WebhookDelivery::query()->create([
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'delivery_id' => $deliveryId,
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'received_at' => $receivedAt,
        ]);
    }

    WebhookReceipt::query()->create([
        'tenant_id' => $tenantId,
        'provider_id' => RESIDUE_PROVIDER,
        'connection_id' => $connectionId,
        'delivery_id' => 'd-1',
        'first_seen_at' => $receivedAt,
        'duplicate_count' => 0,
    ]);

    $bytes = "employee_id,name\n1,Ada\n";
    $directory = sys_get_temp_dir().'/blb-residue-'.getmypid();
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory.'/payroll.csv';
    file_put_contents($path, $bytes);
    app(FileExchangeLedger::class)->record(
        $connection->fresh(),
        new ProviderFile('payroll.csv', hash('sha256', $bytes), $path),
        FileExchangeRecord::DIRECTION_IMPORT,
        'workforce.import',
        $actor,
        schemaVersion: 'hr2000-sbg-1',
        evidenceReference: 'evidence-residue',
    );

    $issuedAt = new DateTimeImmutable(now()->toISOString());
    $credential = app(ProviderCredentialStore::class)->issue(
        new ProviderAuthenticationRequest($tenantId, $connectionId, 'blb-people-connector', ['employee_directory:read']),
        $connection->fresh(),
        'key-residue',
        'base-integration:residue-secret',
        $issuedAt,
        $issuedAt->modify('+4 minutes'),
    );

    // Activating the replacement deactivates the source for the same company
    // scope; credential issuance above must happen while the source is still
    // active. Remap and retirement still accept the deactivated source.
    $replacement = $store->activate((int) $store->configure($scope, RESIDUE_REPLACEMENT)->id);
    $replacementId = (int) $replacement->id;

    app(ProviderReplacementService::class)->remap($actor, $connectionId, $replacementId, [
        new ProviderIdentityMapping(
            residueRef(RESIDUE_PROVIDER, WorkforceResourceType::Employee, 'RES-EMP-1'),
            residueRef(RESIDUE_REPLACEMENT, WorkforceResourceType::Employee, 'NEW-EMP-1'),
        ),
    ], 'residue-remap-2026-09-07', $at);

    app(ConnectionRetirementService::class)->retire($actor, $connectionId, 'retire-residue-2026-09-07', $at);

    // Retirement refuses open issues, so the open-issue flag is seeded after
    // freeze — the same raw-write style the guard tests use when the supported
    // path cannot leave a connection in the state under test.
    $issueId = (int) DB::table('people_connector_connector_reconciliation_issues')->insertGetId([
        'tenant_id' => $tenantId,
        'connection_id' => $connectionId,
        'workforce_entity_id' => null,
        'issue_key' => 'residue-open',
        'kind' => 'mapping_conflict',
        'resource_type' => WorkforceResourceType::Employee->value,
        'external_id' => 'RES-EMP-2',
        'severity' => 'warning',
        'status' => ReconciliationIssue::STATUS_OPEN,
        'details' => json_encode(['reason_code' => 'review_required']),
        'first_seen_at' => $at,
        'last_seen_at' => $at,
        'resolved_at' => null,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'connectionId' => $connectionId,
        'replacementId' => $replacementId,
        'actor' => $actor,
        'credentialId' => $credential->credentialId,
        'issueId' => $issueId,
        'connection' => ProviderConnection::query()->findOrFail($connectionId),
    ];
}

/** @return array<string, int> */
function residueTableCounts(): array
{
    return [
        'external_identities' => ExternalIdentity::query()->count(),
        'sync_checkpoints' => SyncCheckpoint::query()->count(),
        'sync_checkpoint_events' => SyncCheckpointEvent::query()->count(),
        'webhook_deliveries' => WebhookDelivery::query()->count(),
        'webhook_receipts' => WebhookReceipt::query()->count(),
        'file_exchange_records' => FileExchangeRecord::query()->count(),
        'provider_credentials' => ProviderCredentialRecord::query()
            ->withoutCompanyScope('Residue audit assertions count every credential row.')
            ->count(),
        'reconciliation_issues' => ReconciliationIssue::query()->count(),
        'operator_audits' => OperatorAudit::query()->count(),
    ];
}

function residueRow(array $reportRows, string $table): object
{
    foreach ($reportRows as $row) {
        if ($row->table === $table) {
            return $row;
        }
    }

    throw new RuntimeException("Missing residue row for {$table}");
}

test('an active or inactive connection is refused with no audit row', function (): void {
    residueAuthz(true);
    [$tenant, $company] = createTenantWithCompany(['name' => 'Residue Active Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $active = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), RESIDUE_PROVIDER)->id);
    $actor = new Actor(PrincipalType::USER, 8102, (int) $company->id, tenantId: (int) $tenant->id);
    $auditsBefore = OperatorAudit::query()->count();

    expect(fn () => app(ConnectionResidueReporter::class)->for($actor, (int) $active->id))
        ->toThrow(ConnectionResidueException::class)
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);

    $inactive = $store->configure(ProviderScope::company((int) $company->id), 'test.residue-inactive');
    expect($inactive->status)->toBe(ProviderConnection::STATUS_INACTIVE)
        ->and(fn () => app(ConnectionResidueReporter::class)->for($actor, (int) $inactive->id))
        ->toThrow(ConnectionResidueException::class)
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('a retired connection reports exact counts and raises the four decision flags', function (): void {
    $f = residueRetiredFixture('Residue Seeded Tenant');
    residueAuthz(true);
    app(TenantContext::class)->set($f['tenantId']);

    $report = app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']);

    expect($report->needsDecision())->toBeTrue()
        // Company + two employees; remap retires one employee identity on the source.
        ->and(residueRow($report->rows, 'people_connector_connector_external_identities')->count)->toBe(3)
        ->and(residueRow($report->rows, 'people_connector_connector_external_identities')->flagged)->toBeTrue()
        ->and(residueRow($report->rows, 'people_connector_connector_sync_checkpoints')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_sync_checkpoint_events')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_webhook_deliveries')->count)->toBe(3)
        ->and(residueRow($report->rows, 'people_connector_connector_webhook_receipts')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_file_exchange_records')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_file_exchange_records')->flagged)->toBeTrue()
        ->and(residueRow($report->rows, 'people_connector_connector_provider_credentials')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_provider_credentials')->flagged)->toBeTrue()
        ->and(residueRow($report->rows, 'people_connector_connector_reconciliation_issues')->count)->toBe(1)
        ->and(residueRow($report->rows, 'people_connector_connector_reconciliation_issues')->flagged)->toBeTrue()
        ->and(residueRow($report->rows, 'people_connector_connector_external_identities')->retentionLabel())->toBe('kept')
        ->and(residueRow($report->rows, 'people_connector_connector_webhook_deliveries')->retentionLabel())->toBe('365');

    $operator = User::factory()->create(['company_id' => $f['companyId']]);
    $exit = Artisan::call('connector:connection:residue', [
        'connection' => $f['connectionId'],
        '--tenant' => $f['tenantId'],
        '--as' => $operator->id,
    ]);
    expect($exit)->toBe(1);
});

test('revoking the credential and resolving the issue clears those flags', function (): void {
    $f = residueRetiredFixture('Residue Cleared Flags Tenant');
    residueAuthz(true);
    app(TenantContext::class)->set($f['tenantId']);

    app(ProviderCredentialStore::class)->revoke(
        $f['credentialId'],
        new DateTimeImmutable(now()->toISOString()),
    );
    app(ReconciliationIssueStore::class)->resolve($f['issueId']);

    $report = app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']);

    expect(residueRow($report->rows, 'people_connector_connector_provider_credentials')->flagged)->toBeFalse()
        ->and(residueRow($report->rows, 'people_connector_connector_reconciliation_issues')->flagged)->toBeFalse()
        ->and(residueRow($report->rows, 'people_connector_connector_external_identities')->flagged)->toBeTrue()
        ->and(residueRow($report->rows, 'people_connector_connector_file_exchange_records')->flagged)->toBeTrue();
});

test("a sibling tenant's connection rows are never counted and a foreign operator is refused", function (): void {
    $mine = residueRetiredFixture('Residue Mine Tenant');
    $theirs = residueRetiredFixture('Residue Theirs Tenant');
    residueAuthz(true);
    app(TenantContext::class)->set($mine['tenantId']);

    $report = app(ConnectionResidueReporter::class)->for($mine['actor'], $mine['connectionId']);

    expect(residueRow($report->rows, 'people_connector_connector_webhook_deliveries')->count)->toBe(3)
        ->and(residueRow($report->rows, 'people_connector_connector_external_identities')->count)->toBe(3);

    $foreign = new Actor(PrincipalType::USER, 8200, $theirs['companyId'], tenantId: $theirs['tenantId']);
    expect(fn () => app(ConnectionResidueReporter::class)->for($foreign, $mine['connectionId']))
        ->toThrow(ProviderAuthorizationException::class);
});

test('a row of another tenant that names this connection id is not counted', function (): void {
    $mine = residueRetiredFixture('Residue Tenant Guard Mine');
    $theirs = residueRetiredFixture('Residue Tenant Guard Theirs');
    residueAuthz(true);
    app(TenantContext::class)->set($mine['tenantId']);

    // A delivery labelled with the sibling tenant but carrying this connection's id:
    // the tenant filter, not the connection filter, is what keeps it out.
    DB::table('people_connector_connector_webhook_deliveries')->insert([
        'tenant_id' => $theirs['tenantId'],
        'connection_id' => $mine['connectionId'],
        'delivery_id' => 'foreign-tenant-row',
        'status' => WebhookDelivery::STATUS_DELIVERED,
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(ConnectionResidueReporter::class)->for($mine['actor'], $mine['connectionId']);

    expect(residueRow($report->rows, 'people_connector_connector_webhook_deliveries')->count)->toBe(3);
});

test('exactly one operator audit row is written and no other table changes', function (): void {
    $f = residueRetiredFixture('Residue Audit Only Tenant');
    residueAuthz(true);
    app(TenantContext::class)->set($f['tenantId']);
    $before = residueTableCounts();

    app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']);

    $after = residueTableCounts();
    expect($after['operator_audits'])->toBe($before['operator_audits'] + 1);
    unset($before['operator_audits'], $after['operator_audits']);
    expect($after)->toBe($before);

    $audit = OperatorAudit::query()->latest('id')->firstOrFail();
    expect($audit->operation)->toBe(OperatorAuditOperation::ConnectionResidueReported)
        ->and($audit->connection_id)->toBe($f['connectionId']);
});

test('an operator without the capability is refused before the audit row', function (): void {
    $f = residueRetiredFixture('Residue Denied Tenant');
    residueAuthz(false);
    app(TenantContext::class)->set($f['tenantId']);
    $auditsBefore = OperatorAudit::query()->count();

    expect(fn () => app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']))
        ->toThrow(\App\Base\Authz\Exceptions\AuthorizationDeniedException::class)
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('the command turns a platform authorization denial into a clean non-zero exit', function (): void {
    $f = residueRetiredFixture('Residue Cmd Denied Tenant');
    residueAuthz(false);
    app(TenantContext::class)->set($f['tenantId']);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);
    $auditsBefore = OperatorAudit::query()->count();

    $exit = Artisan::call('connector:connection:residue', [
        'connection' => $f['connectionId'],
        '--as' => $operator->id,
        '--tenant' => $f['tenantId'],
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Authorization denied')
        ->and(OperatorAudit::query()->count())->toBe($auditsBefore);
});

test('the command refuses to run without a tenant scope', function (): void {
    $f = residueRetiredFixture('Residue No Tenant Tenant');
    residueAuthz(true);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);

    $exit = Artisan::call('connector:connection:residue', [
        'connection' => $f['connectionId'],
        '--as' => $operator->id,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('tenant');
});

test('an identity deactivated before retirement is not an open decision', function (): void {
    $f = residueRetiredFixture('Residue Departed');

    // Everyone the connection still names has left: their identities are
    // closed, state inactive, and nothing replaced them because there was
    // nobody to hand over. Raw-written in the style this file already uses for
    // the open issue, because retirement has frozen the supported path.
    DB::table('people_connector_connector_external_identities')
        ->where('tenant_id', $f['tenantId'])
        ->where('connection_id', $f['connectionId'])
        ->whereNull('replaced_by_identity_id')
        ->update(['state' => ExternalIdentity::STATE_INACTIVE, 'effective_to' => now()]);

    $report = app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']);
    $identities = residueRow($report->rows, 'people_connector_connector_external_identities');

    expect($identities->flagged)->toBeFalse();
});

test('checkpoint events of a sibling connection in the same tenant are not counted', function (): void {
    $f = residueRetiredFixture('Residue Sibling Checkpoint');
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $f['replacementId'],
        'workforce',
        new WorkforceChangePage(changes: [], asOf: new DateTimeImmutable, resumeCursor: 'replacement-1', complete: true),
        expectedVersion: 0,
    );

    $report = app(ConnectionResidueReporter::class)->for($f['actor'], $f['connectionId']);
    $events = residueRow($report->rows, 'people_connector_connector_sync_checkpoint_events');
    $mine = SyncCheckpointEvent::query()->whereIn(
        'checkpoint_id',
        SyncCheckpoint::query()->forTenant($f['tenantId'])->where('connection_id', $f['connectionId'])->pluck('id'),
    )->count();

    expect($events->count)->toBe($mine)
        ->and($events->count)->toBeLessThan(SyncCheckpointEvent::query()->count());
});
