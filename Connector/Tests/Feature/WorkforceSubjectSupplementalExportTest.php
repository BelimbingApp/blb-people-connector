<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Database\Services\DataShare\DataSharePrivateStorage;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectExporter;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectImporter;
use App\Domains\PeopleConnector\Connector\Testing\SyntheticSupplementalExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    supplementalExportAuthz(true);
});

afterEach(fn () => app(TenantContext::class)->clear());

function supplementalExportAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private readonly bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
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

function supplementalExportRegister(SyntheticSupplementalExporter $exporter): SyntheticSupplementalExporter
{
    app()->instance(SyntheticSupplementalExporter::class, $exporter);
    app()->tag([SyntheticSupplementalExporter::class], ExportsSupplementalSubjectRecords::class);

    return $exporter;
}

/**
 * One tenant, one company connection, one company projection and N employees.
 *
 * @return array{tenantId: int, companyId: int, connectionId: int, companyEntityId: int, entityIds: array<string, int>, actor: Actor}
 */
function supplementalExportTenant(string $name, string ...$employees): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company($companyId), 'test.supplemental');
    $connectionId = (int) $connections->activate((int) $connection->id)->id;
    $at = new DateTimeImmutable('2026-09-08T06:00:00+00:00');
    $companyRef = new ExternalReference('test.supplemental', WorkforceResourceType::Company, 'SUPP-CO');
    $projections = app(WorkforceProjectionStore::class);
    $companyEntityId = (int) $projections->upsert($connectionId, new WorkforceCompany($companyRef, 'Supplemental Co', true, $at))->workforce_entity_id;
    $entityIds = [];
    foreach ($employees as $externalId) {
        $employee = $projections->upsert($connectionId, new WorkforceEmployee(
            new ExternalReference('test.supplemental', WorkforceResourceType::Employee, $externalId),
            $companyRef,
            "Person {$externalId}",
            true,
            $at,
            $at,
        ));
        $entityIds[$externalId] = (int) $employee->workforce_entity_id;
    }

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'connectionId' => $connectionId,
        'companyEntityId' => $companyEntityId,
        'entityIds' => $entityIds,
        'actor' => new Actor(PrincipalType::USER, 5151, $companyId, tenantId: $tenantId),
    ];
}

/** @return array<string, mixed> */
function supplementalExportPackage(array $tenant, int $entityId): array
{
    app(TenantContext::class)->set($tenant['tenantId']);
    $result = app(WorkforceSubjectExporter::class)->export($tenant['actor'], $entityId);

    return json_decode(Storage::disk('local')->get($result->path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, int> */
function supplementalExportTableCounts(): array
{
    $counts = [];
    foreach (Schema::getTableListing(null, false) as $table) {
        if (str_starts_with($table, 'people_connector_')) {
            $counts[$table] = DB::table($table)->count();
        }
    }
    ksort($counts);

    return $counts;
}

test('with no exporter registered the export says it is partial and carries no supplemental section', function (): void {
    $tenant = supplementalExportTenant('Supplemental None', 'NONE-EMP');

    $package = supplementalExportPackage($tenant, $tenant['entityIds']['NONE-EMP']);

    expect($package['supplemental_missing'])->toBeTrue()
        ->and($package['supplemental_exporters'])->toBe([])
        ->and($package['supplemental'])->toBe([]);
});

test('the export carries the registered exporter section for the subject and nothing for a sibling employee of the same company', function (): void {
    $tenant = supplementalExportTenant('Supplemental Sibling', 'SUBJECT-EMP', 'SIBLING-EMP');
    $exporter = supplementalExportRegister(new SyntheticSupplementalExporter);
    $subjectId = $tenant['entityIds']['SUBJECT-EMP'];
    $exporter->seed($tenant['tenantId'], $tenant['companyEntityId'], $subjectId, 'people_skill_assessments', ['workforce_entity_id' => $subjectId, 'skill' => 'SUBJECT-SKILL', 'score' => 4]);
    $exporter->seed($tenant['tenantId'], $tenant['companyEntityId'], $tenant['entityIds']['SIBLING-EMP'], 'people_skill_assessments', ['workforce_entity_id' => $tenant['entityIds']['SIBLING-EMP'], 'skill' => 'SIBLING-SKILL', 'score' => 2]);

    $package = supplementalExportPackage($tenant, $subjectId);
    $encoded = json_encode($package, JSON_THROW_ON_ERROR);

    expect($package['supplemental_missing'])->toBeFalse()
        ->and($package['supplemental_exporters'])->toBe(['people.synthetic'])
        ->and($package['supplemental']['people.synthetic']['people_skill_assessments'])->toEqualCanonicalizing([['workforce_entity_id' => $subjectId, 'skill' => 'SUBJECT-SKILL', 'score' => 4]])
        ->and($encoded)->not->toContain('SIBLING-SKILL');
});

test('a section row of a sibling company or another tenant is never exported because the exporter sees the subject tenant and company only', function (): void {
    $other = supplementalExportTenant('Supplemental Other Tenant', 'OTHER-EMP');
    $tenant = supplementalExportTenant('Supplemental Scope', 'SCOPE-EMP');
    $exporter = supplementalExportRegister(new SyntheticSupplementalExporter);
    $subjectId = $tenant['entityIds']['SCOPE-EMP'];
    // Same subject id under the sibling company entity, and under the other tenant.
    $exporter->seed($tenant['tenantId'], $other['companyEntityId'], $subjectId, 'people_training_participants', ['workforce_entity_id' => $subjectId, 'course' => 'SIBLING-COMPANY-COURSE']);
    $exporter->seed($other['tenantId'], $tenant['companyEntityId'], $subjectId, 'people_training_participants', ['workforce_entity_id' => $subjectId, 'course' => 'OTHER-TENANT-COURSE']);
    $exporter->seed($tenant['tenantId'], $tenant['companyEntityId'], $subjectId, 'people_training_participants', ['workforce_entity_id' => $subjectId, 'course' => 'SUBJECT-COURSE']);

    $package = supplementalExportPackage($tenant, $subjectId);
    $encoded = json_encode($package, JSON_THROW_ON_ERROR);

    expect($exporter->calls())->toBe([['tenant_id' => $tenant['tenantId'], 'company_entity_id' => $tenant['companyEntityId'], 'stable_id' => (string) $subjectId]])
        ->and($encoded)->toContain('SUBJECT-COURSE')
        ->and($encoded)->not->toContain('SIBLING-COMPANY-COURSE')
        ->and($encoded)->not->toContain('OTHER-TENANT-COURSE');
});

test('restore records a non-restorable section as not_restored and writes no supplemental row', function (): void {
    $source = supplementalExportTenant('Supplemental Restore Source', 'RESTORE-EMP');
    $exporter = supplementalExportRegister(new SyntheticSupplementalExporter(restorable: false));
    $subjectId = $source['entityIds']['RESTORE-EMP'];
    $exporter->seed($source['tenantId'], $source['companyEntityId'], $subjectId, 'people_skill_assessments', ['workforce_entity_id' => $subjectId, 'skill' => 'RESTORE-SKILL']);
    app(TenantContext::class)->set($source['tenantId']);
    $export = app(WorkforceSubjectExporter::class)->export($source['actor'], $subjectId);

    $target = supplementalExportTenant('Supplemental Restore Target');
    Storage::disk('local')->put(app(DataSharePrivateStorage::class)->incomingPath($export->packageId), Storage::disk('local')->get($export->path));
    $before = supplementalExportTableCounts();

    $result = app(WorkforceSubjectImporter::class)->import($target['actor'], $target['connectionId'], $export->packageId);
    $after = supplementalExportTableCounts();

    $subjectOwn = [(new WorkforceEntity)->getTable(), (new ExternalIdentity)->getTable(), (new WorkforceSnapshot)->getTable(), (new OperatorAudit)->getTable()];
    expect($result->toArray()['not_restored'])->toBe(['people.synthetic'])
        ->and($result->toArray()['supplemental'])->toBe([])
        ->and(array_diff_key($after, array_flip($subjectOwn)))->toBe(array_diff_key($before, array_flip($subjectOwn)))
        ->and($after[(new ExternalIdentity)->getTable()])->toBe($before[(new ExternalIdentity)->getTable()] + 1);
});

test('restore re-emits a restorable section verbatim and writes nothing for it', function (): void {
    $source = supplementalExportTenant('Supplemental Verbatim Source', 'VERBATIM-EMP');
    $exporter = supplementalExportRegister(new SyntheticSupplementalExporter(restorable: true));
    $subjectId = $source['entityIds']['VERBATIM-EMP'];
    $rows = [['workforce_entity_id' => $subjectId, 'skill' => 'VERBATIM-SKILL', 'score' => 3]];
    $exporter->seed($source['tenantId'], $source['companyEntityId'], $subjectId, 'people_skill_assessments', $rows[0]);
    app(TenantContext::class)->set($source['tenantId']);
    $export = app(WorkforceSubjectExporter::class)->export($source['actor'], $subjectId);

    $target = supplementalExportTenant('Supplemental Verbatim Target');
    Storage::disk('local')->put(app(DataSharePrivateStorage::class)->incomingPath($export->packageId), Storage::disk('local')->get($export->path));

    $result = app(WorkforceSubjectImporter::class)->import($target['actor'], $target['connectionId'], $export->packageId);

    expect($result->toArray()['not_restored'])->toBe([])
        ->and($result->toArray()['supplemental'])->toEqualCanonicalizing(['people.synthetic' => ['people_skill_assessments' => $rows]]);
});

test('an actor without the export capability is refused before any exporter is invoked', function (): void {
    $tenant = supplementalExportTenant('Supplemental Refused', 'REFUSED-EMP');
    $exporter = supplementalExportRegister(new SyntheticSupplementalExporter);
    supplementalExportAuthz(false);
    app(TenantContext::class)->set($tenant['tenantId']);

    expect(fn () => app(WorkforceSubjectExporter::class)->export($tenant['actor'], $tenant['entityIds']['REFUSED-EMP']))
        ->toThrow(ProviderAuthorizationException::class, 'lacks the subject-export capability');
    expect($exporter->calls())->toBe([])
        ->and(Storage::disk('local')->allFiles('data-share/outgoing'))->toBe([]);
});

test('the operator audit row names the contributing exporters', function (): void {
    $tenant = supplementalExportTenant('Supplemental Audit', 'AUDIT-EMP');
    supplementalExportRegister(new SyntheticSupplementalExporter);

    supplementalExportPackage($tenant, $tenant['entityIds']['AUDIT-EMP']);

    $audit = OperatorAudit::query()->forTenant($tenant['tenantId'])->where('operation', OperatorAuditOperation::SubjectHistoryExported->value)->sole();
    expect($audit->after_summary['supplemental_exporters'])->toBe(['people.synthetic'])
        ->and($audit->after_summary['supplemental_missing'])->toBeFalse();
});
