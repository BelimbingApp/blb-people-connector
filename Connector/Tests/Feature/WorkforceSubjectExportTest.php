<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectExportException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    workforceSubjectExportAuthz(true);
});

function workforceSubjectExportAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private readonly bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException('connector', 'subject_export', 'The operator lacks the subject-export capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * @return array{tenantId: int, companyId: int, connectionId: int, entityId: int, externalId: string, actor: Actor}
 */
function workforceSubjectExportFixture(string $externalId): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Subject Export Tenant']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company($companyId), 'test.subject-export');
    $connectionId = (int) $connections->activate((int) $connection->id)->id;
    $at = new DateTimeImmutable('2026-09-06T06:00:00+00:00');
    $companyRef = new ExternalReference('test.subject-export', WorkforceResourceType::Company, 'SUBJECT-CO');
    $employeeRef = new ExternalReference('test.subject-export', WorkforceResourceType::Employee, $externalId);
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($connectionId, new WorkforceCompany($companyRef, 'Subject Co', true, $at));
    $employee = $projections->upsert($connectionId, new WorkforceEmployee(
        $employeeRef,
        $companyRef,
        "Person {$externalId}",
        true,
        $at,
        $at,
        email: strtolower($externalId).'@example.test',
    ));

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'connectionId' => $connectionId,
        'entityId' => (int) $employee->workforce_entity_id,
        'externalId' => $externalId,
        'actor' => new Actor(PrincipalType::USER, 4242, $companyId, tenantId: $tenantId),
    ];
}

test('an operator exports only one workforce subject and redacts credential references and payload hashes', function (): void {
    $subject = workforceSubjectExportFixture('SUBJECT-EMP-1');
    app(TenantContext::class)->set($subject['tenantId']);

    $subjectHash = str_repeat('a', 64);
    $siblingHash = str_repeat('b', 64);
    $issues = app(ReconciliationIssueStore::class);
    $issues->report(
        $subject['connectionId'],
        'subject-export-issue',
        'payload_mismatch',
        new ReconciliationIssueDetails(payloadHash: $subjectHash),
        WorkforceResourceType::Employee->value,
        $subject['externalId'],
        $subject['entityId'],
    );

    // The sibling fixture owns another tenant. Add an in-tenant sibling so a
    // missing workforce_entity_id predicate produces an immediately visible leak.
    $connectionId = $subject['connectionId'];
    $companyRef = new ExternalReference('test.subject-export', WorkforceResourceType::Company, 'SUBJECT-CO');
    $siblingRef = new ExternalReference('test.subject-export', WorkforceResourceType::Employee, 'SIBLING-IN-TENANT');
    $at = new DateTimeImmutable('2026-09-06T06:01:00+00:00');
    $siblingProjection = app(WorkforceProjectionStore::class)->upsert($connectionId, new WorkforceEmployee(
        $siblingRef,
        $companyRef,
        'Sibling Person',
        true,
        $at,
        $at,
    ));
    $issues->report(
        $connectionId,
        'sibling-export-issue',
        'payload_mismatch',
        new ReconciliationIssueDetails(payloadHash: $siblingHash),
        WorkforceResourceType::Employee->value,
        'SIBLING-IN-TENANT',
        (int) $siblingProjection->workforce_entity_id,
    );

    $identity = ExternalIdentity::query()
        ->forTenant($subject['tenantId'])
        ->where('workforce_entity_id', $subject['entityId'])
        ->firstOrFail();
    DB::table((new WorkforceSnapshot)->getTable())->insert([
        'tenant_id' => $subject['tenantId'],
        'connection_id' => $connectionId,
        'workforce_entity_id' => $subject['entityId'],
        'external_identity_id' => $identity->id,
        'event_type' => 'projection_upserted',
        'resource_type' => WorkforceResourceType::Employee->value,
        'event_key' => hash('sha256', 'subject-export-sensitive-fixture'),
        'effective_at' => $at,
        'observed_at' => $at,
        'payload' => json_encode(['credential_reference' => 'vault://subject-secret', 'payload_hash' => $subjectHash], JSON_THROW_ON_ERROR),
        'provenance' => json_encode(['source' => 'subject-export-test'], JSON_THROW_ON_ERROR),
        'created_at' => $at,
    ]);

    app(OperatorAuditLog::class)->record(
        $subject['actor'],
        OperatorAuditOperation::IdentitiesRemapped,
        $connectionId,
        null,
        null,
        ['external_ids' => [$subject['externalId'], 'SIBLING-IN-TENANT']],
        ['external_ids' => [$subject['externalId'], 'SIBLING-IN-TENANT']],
    );

    $result = app(WorkforceSubjectExporter::class)->export($subject['actor'], $subject['entityId']);
    $package = json_decode(Storage::disk('local')->get($result->path), true, flags: JSON_THROW_ON_ERROR);
    $encoded = json_encode($package, JSON_THROW_ON_ERROR);

    expect($package['format'])->toBe('belimbing-data-share/people-connector-subject/v1')
        ->and($package['subject']['workforce_entity_id'])->toBe($subject['entityId'])
        ->and($package['import_policy'])->toBe('none')
        ->and($encoded)->toContain($subject['externalId'])
        ->and($encoded)->not->toContain('SIBLING-IN-TENANT')
        ->and($encoded)->not->toContain('vault://subject-secret')
        ->and($encoded)->not->toContain($subjectHash)
        ->and($encoded)->not->toContain($siblingHash)
        ->and($package['tables']['people_connector_connector_reconciliation_issues'][0]['details']['payload_hash'])->toBeNull()
        ->and($package['tables']['people_connector_connector_operator_audits'][0]['before_summary']['external_ids'])->toBe([$subject['externalId']]);

    expect(OperatorAudit::query()->forTenant($subject['tenantId'])->where('operation', OperatorAuditOperation::SubjectHistoryExported->value)->count())->toBe(1);
});

test('a workforce subject export refuses an entity from another tenant before writing a package', function (): void {
    $current = workforceSubjectExportFixture('CURRENT-TENANT');
    $other = workforceSubjectExportFixture('OTHER-TENANT');
    app(TenantContext::class)->set($current['tenantId']);

    expect(fn () => app(WorkforceSubjectExporter::class)->export($current['actor'], $other['entityId']))
        ->toThrow(WorkforceSubjectExportException::class);
    expect(Storage::disk('local')->allFiles('data-share/outgoing'))->toBe([]);
});

test('a workforce subject export requires its dedicated operator capability', function (): void {
    $subject = workforceSubjectExportFixture('CAPABILITY-SUBJECT');
    workforceSubjectExportAuthz(false);

    expect(fn () => app(WorkforceSubjectExporter::class)->export($subject['actor'], $subject['entityId']))
        ->toThrow(ProviderAuthorizationException::class, 'lacks the subject-export capability');
    expect(Storage::disk('local')->allFiles('data-share/outgoing'))->toBe([]);
});

test('a workforce subject export refuses an operator outside the subject company', function (): void {
    $subject = workforceSubjectExportFixture('COMPANY-SUBJECT');
    $otherCompanyActor = new Actor(
        PrincipalType::USER,
        4343,
        $subject['companyId'] + 10_000,
        tenantId: $subject['tenantId'],
    );

    expect(fn () => app(WorkforceSubjectExporter::class)->export($otherCompanyActor, $subject['entityId']))
        ->toThrow(ProviderAuthorizationException::class, 'must belong to the subject tenant and company');
    expect(Storage::disk('local')->allFiles('data-share/outgoing'))->toBe([]);
});

test('a subject linked to multiple platform companies requires an explicit scope decision', function (): void {
    $subject = workforceSubjectExportFixture('MULTI-COMPANY-SUBJECT');
    $otherCompany = Company::factory()->create(['tenant_id' => $subject['tenantId']]);
    $connections = app(ProviderConnectionStore::class);
    $otherConnection = $connections->configure(ProviderScope::company((int) $otherCompany->id), 'test.subject-export-other');
    $otherConnectionId = (int) $connections->activate((int) $otherConnection->id)->id;
    $at = new DateTimeImmutable('2026-09-06T06:02:00+00:00');
    ExternalIdentity::query()->create([
        'tenant_id' => $subject['tenantId'],
        'connection_id' => $otherConnectionId,
        'workforce_entity_id' => $subject['entityId'],
        'provider_id' => 'test.subject-export-other',
        'resource_type' => WorkforceResourceType::Employee->value,
        'external_id' => 'MULTI-COMPANY-ALIAS',
        'external_id_hash' => hash('sha256', 'MULTI-COMPANY-ALIAS'),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => $at,
        'last_observed_at' => $at,
    ]);

    expect(fn () => app(WorkforceSubjectExporter::class)->export($subject['actor'], $subject['entityId']))
        ->toThrow(WorkforceSubjectExportException::class, 'more than one platform company');
    expect(Storage::disk('local')->allFiles('data-share/outgoing'))->toBe([]);
});

test('the operator command emits the protected package receipt as json', function (): void {
    $subject = workforceSubjectExportFixture('COMMAND-SUBJECT');
    $operator = User::factory()->create(['company_id' => $subject['companyId']]);

    $exit = Artisan::call('people-connector:subject-export', [
        'entity' => $subject['entityId'],
        '--tenant' => $subject['tenantId'],
        '--as' => $operator->id,
        '--json' => true,
    ]);
    $receipt = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($receipt['path'])->toStartWith('data-share/outgoing/')
        ->and(Storage::disk('local')->exists($receipt['path']))->toBeTrue();
});
