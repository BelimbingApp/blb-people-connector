<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType as SubjectResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Provider\Services\NativeWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Services\ProjectionWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\SynchronizedWorkforce;

/**
 * A caller must not be able to tell which side answered from the shape of a
 * denial. The two resolvers read completely different tables — Core employees
 * against synchronized projections — so parity here is on the refusal, not on
 * the record: the same scenario must produce the same answer on both sides.
 */
test('the native and projection resolvers refuse the same scenarios the same way', function (
    string $scenario,
    ?WorkforceSubjectRefusal $expected,
): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $elsewhere = CompanyIsolationContract::twoCompaniesInOneTenant('Far Alpha', 'Far Beta');

    $nativeHere = Employee::factory()->create([
        'company_id' => $fixture->alphaCompany->id,
        'status' => 'active',
    ]);
    $nativeSibling = Employee::factory()->create([
        'company_id' => $fixture->betaCompany->id,
        'status' => 'active',
    ]);
    $nativeInactive = Employee::factory()->create([
        'company_id' => $fixture->alphaCompany->id,
        'status' => 'inactive',
    ]);
    $nativeElsewhere = Employee::factory()->create([
        'company_id' => $elsewhere->alphaCompany->id,
        'status' => 'active',
    ]);

    $projectedHere = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    $projectedSibling = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->betaCompanyEntityId);
    $projectedInactive = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId, active: false);
    $projectedElsewhere = SynchronizedWorkforce::inCompany($elsewhere->tenantId, $elsewhere->alphaCompanyEntityId);
    $employee = SubjectResourceType::Employee->value;

    // Same scenario on both sides. Only the id space differs: People answers
    // for platform companies and employees, the connector for the workforce
    // entities it synchronized.
    [$native, $projected, $clearTenant] = match ($scenario) {
        'resolved' => [
            [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeHere->getKey()],
            [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedHere[$employee]],
            false,
        ],
        'sibling company' => [
            [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeSibling->getKey()],
            [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedSibling[$employee]],
            false,
        ],
        'deactivated' => [
            [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeInactive->getKey()],
            [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedInactive[$employee]],
            false,
        ],
        'another tenant' => [
            [$elsewhere->tenantId, $elsewhere->alphaCompany->id, (string) $nativeElsewhere->getKey()],
            [$elsewhere->tenantId, $elsewhere->alphaCompanyEntityId, (string) $projectedElsewhere[$employee]],
            false,
        ],
        'a stable id that is not a key' => [
            [$fixture->tenantId, $fixture->alphaCompany->id, 'not-a-native-id'],
            [$fixture->tenantId, $fixture->alphaCompanyEntityId, 'not-a-native-id'],
            false,
        ],
        'no tenant on the subject' => [
            [null, $fixture->alphaCompany->id, (string) $nativeHere->getKey()],
            [null, $fixture->alphaCompanyEntityId, (string) $projectedHere[$employee]],
            false,
        ],
        'no company on the subject' => [
            [$fixture->tenantId, null, (string) $nativeHere->getKey()],
            [$fixture->tenantId, null, (string) $projectedHere[$employee]],
            false,
        ],
        'no ambient tenant context' => [
            [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeHere->getKey()],
            [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedHere[$employee]],
            true,
        ],
    };

    if ($clearTenant) {
        app(TenantContext::class)->clear();
    } else {
        app(TenantContext::class)->set($fixture->tenantId);
    }

    $nativeResolution = app(NativeWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        $native[0], $native[1], SubjectResourceType::Employee, $native[2],
    ));
    $projectedResolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        $projected[0], $projected[1], SubjectResourceType::Employee, $projected[2],
    ));

    expect($projectedResolution->refusal)->toBe($expected)
        ->and($nativeResolution->refusal)->toBe($expected)
        ->and($projectedResolution->refusal)->toBe($nativeResolution->refusal)
        ->and($projectedResolution->record === null)->toBe($nativeResolution->record === null);
})->with([
    'resolved' => ['resolved', null],
    'sibling company' => ['sibling company', WorkforceSubjectRefusal::WrongCompany],
    'deactivated' => ['deactivated', WorkforceSubjectRefusal::Deactivated],
    'another tenant' => ['another tenant', WorkforceSubjectRefusal::Unknown],
    'a stable id that is not a key' => ['a stable id that is not a key', WorkforceSubjectRefusal::Unknown],
    'no tenant on the subject' => ['no tenant on the subject', WorkforceSubjectRefusal::Unknown],
    'no company on the subject' => ['no company on the subject', WorkforceSubjectRefusal::Unknown],
    'no ambient tenant context' => ['no ambient tenant context', WorkforceSubjectRefusal::Unknown],
]);

test('both resolvers refuse a subject that names a provider neither of them speaks for', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $native = Employee::factory()->create([
        'company_id' => $fixture->alphaCompany->id,
        'status' => 'active',
    ]);
    $projected = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    app(TenantContext::class)->set($fixture->tenantId);

    $foreign = static fn (string $stableId): WorkforceSubject => new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompany->id,
        type: SubjectResourceType::Employee,
        stableId: $stableId,
        externalReference: new ExternalReference(SubjectResourceType::Employee, 'e-1', 'hr2000.sbg'),
    );

    $nativeResolution = app(NativeWorkforceSubjectResolver::class)->resolve($foreign((string) $native->getKey()));
    $projectedResolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Employee,
        stableId: (string) $projected[SubjectResourceType::Employee->value],
        externalReference: new ExternalReference(SubjectResourceType::Employee, 'e-1', 'hr2000.sbg'),
    ));

    expect($nativeResolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown)
        ->and($projectedResolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});
