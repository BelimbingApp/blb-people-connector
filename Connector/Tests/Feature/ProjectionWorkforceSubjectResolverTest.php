<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType as SubjectResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Provider\Services\NativeWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Services\ProjectionWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\SynchronizedWorkforce;

test('the seam answers from projections only where the connector owns workforce identity', function (?string $activeProvider, string $expected): void {
    config()->set('people-connector.active_provider', $activeProvider);

    expect(app(ResolvesWorkforceSubjects::class))->toBeInstanceOf($expected);
})->with([
    // A co-located install synchronizes nothing, so answering from
    // projections there would refuse every subject that actually exists.
    'no provider chosen' => [null, NativeWorkforceSubjectResolver::class],
    'People is the provider' => ['blb-people', NativeWorkforceSubjectResolver::class],
    'a remote provider' => ['hr2000.sbg', ProjectionWorkforceSubjectResolver::class],
]);

test('every projected subject type resolves inside its tenant and company', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    app(TenantContext::class)->set($fixture->tenantId);
    $resolver = app(ProjectionWorkforceSubjectResolver::class);

    foreach ($records as $type => $entityId) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            tenantId: $fixture->tenantId,
            companyId: $fixture->alphaCompanyEntityId,
            type: SubjectResourceType::from($type),
            stableId: (string) $entityId,
        ));

        expect($resolution->refusal)->toBeNull()
            ->and((int) $resolution->record?->getAttribute('workforce_entity_id'))->toBe($entityId);
    }

    $company = $resolver->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->alphaCompanyEntityId,
    ));

    expect($company->refusal)->toBeNull()
        ->and($company->record)->toBeInstanceOf(WorkforceCompanyProjection::class);
});

test('a subject owned by a sibling company inside the same tenant is refused as the wrong company', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->betaCompanyEntityId);
    app(TenantContext::class)->set($fixture->tenantId);
    $resolver = app(ProjectionWorkforceSubjectResolver::class);

    foreach ($records as $type => $entityId) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            tenantId: $fixture->tenantId,
            companyId: $fixture->alphaCompanyEntityId,
            type: SubjectResourceType::from($type),
            stableId: (string) $entityId,
        ));

        expect($resolution->record)->toBeNull()
            ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::WrongCompany);
    }

    $company = $resolver->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->betaCompanyEntityId,
    ));

    expect($company->refusal)->toBe(WorkforceSubjectRefusal::WrongCompany);
});

test('a deactivated projection is refused as deactivated rather than unknown', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId, active: false);
    app(TenantContext::class)->set($fixture->tenantId);
    $resolver = app(ProjectionWorkforceSubjectResolver::class);

    foreach ($records as $type => $entityId) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            tenantId: $fixture->tenantId,
            companyId: $fixture->alphaCompanyEntityId,
            type: SubjectResourceType::from($type),
            stableId: (string) $entityId,
        ));

        expect($resolution->record)->toBeNull()
            ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Deactivated);
    }
});

test('an entity retired at the identity level is deactivated even while its projection still reads active', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    WorkforceEntity::query()
        ->whereKey($records[SubjectResourceType::Employee->value])
        ->update(['state' => WorkforceEntity::STATE_INACTIVE, 'deactivated_at' => now()]);
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Employee,
        stableId: (string) $records[SubjectResourceType::Employee->value],
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Deactivated);
});

test('the projection resolver fails closed without an ambient tenant context', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->clear();

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->alphaCompanyEntityId,
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a subject from another tenant is refused as unknown, not as the wrong company', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $other = CompanyIsolationContract::twoCompaniesInOneTenant('Other Alpha', 'Other Beta');
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $other->tenantId,
        companyId: $other->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $other->alphaCompanyEntityId,
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a subject the tenant does not own is unknown even when the entity id exists elsewhere', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $other = CompanyIsolationContract::twoCompaniesInOneTenant('Other Alpha', 'Other Beta');
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $other->alphaCompanyEntityId,
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a subject whose entity is a different resource type than it claims is unknown', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Position,
        stableId: (string) $records[SubjectResourceType::Employee->value],
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('the projection resolver refuses the identity-free subject shapes the native resolver refuses', function (mixed $tenantId, mixed $companyId, string $stableId): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $tenantId === 'fixture' ? $fixture->tenantId : $tenantId,
        companyId: $companyId === 'fixture' ? $fixture->alphaCompanyEntityId : $companyId,
        type: SubjectResourceType::Company,
        stableId: $stableId === 'fixture' ? (string) $fixture->alphaCompanyEntityId : $stableId,
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
})->with([
    'no tenant on the subject' => [null, 'fixture', 'fixture'],
    'no company on the subject' => ['fixture', null, 'fixture'],
    'a stable id that is not a native key' => ['fixture', 'fixture', 'not-a-native-id'],
]);

test('a subject naming another provider does not resolve against this connector projection', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->alphaCompanyEntityId,
        externalReference: new ExternalReference(
            SubjectResourceType::Company,
            'company-'.$fixture->alphaCompanyEntityId,
            'hr2000.sbg',
        ),
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a subject naming the provider that actually synced the entity still resolves', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->alphaCompanyEntityId,
        externalReference: new ExternalReference(
            SubjectResourceType::Company,
            'company-'.$fixture->alphaCompanyEntityId,
            'test.people',
        ),
    ));

    expect($resolution->refusal)->toBeNull()
        ->and($resolution->record)->toBeInstanceOf(WorkforceCompanyProjection::class);
});

test('a subject naming another tenant cannot borrow an entity id that exists in this one', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $elsewhere = CompanyIsolationContract::twoCompaniesInOneTenant('Far Alpha', 'Far Beta');
    app(TenantContext::class)->set($fixture->tenantId);

    // Everything names this tenant's live company except the subject's own
    // tenant id. Without the mismatch check the ambient tenant would answer
    // for a subject that claims to belong to a different one.
    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $elsewhere->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: (string) $fixture->alphaCompanyEntityId,
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a stable id with a numeric prefix is refused rather than cast into a real entity', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    // (int) '12-not-mine' is 12. Casting first and asking questions later is
    // how a caller reaches a record it did not name.
    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Company,
        stableId: $fixture->alphaCompanyEntityId.'-not-mine',
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('an entity whose type disagrees with its projection row is refused', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $records = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
    // A bad sync can leave the identity saying one thing and the projection
    // another. The entity row is authoritative about what an identity is.
    WorkforceEntity::query()
        ->whereKey($records[SubjectResourceType::Position->value])
        ->update(['resource_type' => SubjectResourceType::Employee->value]);
    app(TenantContext::class)->set($fixture->tenantId);

    $resolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
        tenantId: $fixture->tenantId,
        companyId: $fixture->alphaCompanyEntityId,
        type: SubjectResourceType::Position,
        stableId: (string) $records[SubjectResourceType::Position->value],
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});
