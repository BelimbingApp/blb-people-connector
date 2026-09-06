<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Database\Services\DataShare\DataSharePrivateStorage;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectImportException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectExporter;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectImporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
});

afterEach(fn () => app(TenantContext::class)->clear());

function subjectImportTenant(string $name, string $provider): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company((int) $company->id), $provider);

    return [
        'tenant' => (int) $tenant->id,
        'company' => (int) $company->id,
        'connection' => (int) $connections->activate((int) $connection->id)->id,
        'actor' => new Actor(PrincipalType::USER, (int) $tenant->id + 1000, (int) $company->id, tenantId: (int) $tenant->id),
    ];
}

test('an operator imports one exported identity history into the current tenant and refuses an existing mapping atomically', function (): void {
    $source = subjectImportTenant('Import Source', 'test.subject-import');
    $at = new DateTimeImmutable('2026-09-06T08:00:00+00:00');
    $companyRef = new ExternalReference('test.subject-import', WorkforceResourceType::Company, 'IMPORT-CO');
    $employeeRef = new ExternalReference('test.subject-import', WorkforceResourceType::Employee, 'IMPORT-EMP');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($source['connection'], new WorkforceCompany($companyRef, 'Import Co', true, $at));
    $employee = $projections->upsert($source['connection'], new WorkforceEmployee(
        $employeeRef, $companyRef, 'Import Person', true, $at, $at,
    ));
    $export = app(WorkforceSubjectExporter::class)->export($source['actor'], (int) $employee->workforce_entity_id);
    $sourceSnapshotCount = WorkforceSnapshot::query()->forTenant($source['tenant'])
        ->where('workforce_entity_id', $employee->workforce_entity_id)->count();

    $target = subjectImportTenant('Import Target', 'test.subject-import');
    $incoming = app(DataSharePrivateStorage::class)->incomingPath($export->packageId);
    $contents = Storage::disk('local')->get($export->path);
    $tampered = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    $tampered['tables'][(new WorkforceSnapshot)->getTable()][0]['connection_id'] += 10_000;
    Storage::disk('local')->put($incoming, json_encode($tampered, JSON_THROW_ON_ERROR));
    expect(fn () => app(WorkforceSubjectImporter::class)->import($target['actor'], $target['connection'], $export->packageId))
        ->toThrow(WorkforceSubjectImportException::class, 'represented subject identity and connection');
    expect(WorkforceEntity::query()->forTenant($target['tenant'])->count())->toBe(0);
    Storage::disk('local')->put($incoming, $contents);
    $operator = User::factory()->create(['company_id' => $target['company']]);
    expect(Artisan::call('connector:identity-import', [
        'package' => $export->packageId,
        '--connection' => $target['connection'],
        '--tenant' => $target['tenant'],
        '--as' => $operator->id,
        '--json' => true,
    ]))->toBe(0);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect(WorkforceEntity::query()->forTenant($target['tenant'])->whereKey($result['workforce_entity_id'])->exists())->toBeTrue()
        ->and(ExternalIdentity::query()->forTenant($target['tenant'])->where('external_id', 'IMPORT-EMP')->value('workforce_entity_id'))->toBe($result['workforce_entity_id'])
        ->and(WorkforceSnapshot::query()->forTenant($target['tenant'])->where('workforce_entity_id', $result['workforce_entity_id'])->count())->toBe($sourceSnapshotCount)
        ->and(WorkforceEntity::query()->whereKey($result['workforce_entity_id'])->value('tenant_id'))->toBe($target['tenant'])
        ->and(OperatorAudit::query()->forTenant($target['tenant'])->where('operation', OperatorAuditOperation::SubjectHistoryImported->value)->count())->toBe(1);

    $before = [WorkforceEntity::count(), ExternalIdentity::count(), WorkforceSnapshot::count(), OperatorAudit::count()];
    expect(fn () => app(WorkforceSubjectImporter::class)->import($target['actor'], $target['connection'], $export->packageId))
        ->toThrow(WorkforceSubjectImportException::class, 'already maps');
    expect([WorkforceEntity::count(), ExternalIdentity::count(), WorkforceSnapshot::count(), OperatorAudit::count()])->toBe($before);
});
