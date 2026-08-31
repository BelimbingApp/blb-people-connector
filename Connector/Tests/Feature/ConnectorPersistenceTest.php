<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ExternalIdentityCollisionException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidWorkforceProvenanceException;
use App\Domains\PeopleConnector\Connector\Exceptions\ReconciliationIssueConflictException;
use App\Domains\PeopleConnector\Connector\Exceptions\SyncCheckpointConflictException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceHistoryConflictException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceProjectionConflictException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceHistory;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function connectorPersistenceReference(
    WorkforceResourceType $type,
    string $externalId,
    string $providerId = 'test.people',
): ExternalReference {
    return new ExternalReference($providerId, $type, $externalId);
}

function connectorPersistenceConnection(int $tenantId, ?int $companyId = null): ProviderConnection
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $scope = $companyId === null ? ProviderScope::tenant() : ProviderScope::company($companyId);
    $connection = $store->configure(
        $scope,
        'test.people',
        new ProviderConnectionMetadata(
            ProviderConnectionMode::RemoteHttp,
            'https://people.example.test',
            'tenant-fixture',
            'base-integration:test-people',
        ),
        'Test People',
        '1.2.0',
        '1.0.0',
    );

    return $store->activate((int) $connection->id);
}

test('connection persistence fails closed and permits only one active provider per tenant or company scope', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Tenant A'], ['name' => 'Company A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Tenant B'], ['name' => 'Company B']);
    $store = app(ProviderConnectionStore::class);

    app(TenantContext::class)->clear();
    expect(fn () => $store->active(ProviderScope::tenant()))
        ->toThrow(TenantContextMissingException::class);

    app(TenantContext::class)->set((int) $tenantA->id);
    $first = $store->configure(ProviderScope::tenant(), 'provider.one');
    $second = $store->configure(ProviderScope::tenant(), 'provider.two');
    $store->activate((int) $first->id);
    $active = $store->activate((int) $second->id);

    expect($store->active(ProviderScope::tenant())?->is($active))->toBeTrue()
        ->and($first->refresh()->status)->toBe(ProviderConnection::STATUS_INACTIVE)
        ->and($first->active_scope_key)->toBeNull()
        ->and($active->active_scope_key)->toBe('tenant');

    expect(fn () => new ProviderConnectionMetadata(
        ProviderConnectionMode::RemoteHttp,
        'https://clientSecret@people.example.test',
    ))->toThrow(InvalidProviderConfigurationException::class, 'credential-free HTTPS');

    expect(fn () => new WorkforceProvenance('provider_sync', reviewReference: 'medical record payload'))
        ->toThrow(InvalidWorkforceProvenanceException::class, 'opaque non-secret identifiers');

    app(TenantContext::class)->set((int) $tenantB->id);
    expect(fn () => $store->find((int) $active->id))
        ->toThrow(ConnectorRecordNotFoundException::class)
        ->and(fn () => $store->configure(ProviderScope::company((int) $companyA->id), 'provider.one'))
        ->toThrow(InvalidProviderConfigurationException::class);
});

test('identity resolution is idempotent tenant isolated and fails closed on collisions', function (): void {
    [$tenantA] = createTenantWithCompany(['name' => 'Identity Tenant A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Identity Tenant B']);
    $connection = connectorPersistenceConnection((int) $tenantA->id);
    $identities = app(WorkforceIdentityStore::class);
    $observedAt = new DateTimeImmutable('2026-08-30T08:00:00+00:00');
    $firstReference = connectorPersistenceReference(WorkforceResourceType::Employee, 'EMP-001');
    $secondReference = connectorPersistenceReference(WorkforceResourceType::Employee, 'EMP-002');

    $first = $identities->resolveOrCreateIdentity((int) $connection->id, $firstReference, $observedAt);
    $retry = $identities->resolveOrCreateIdentity((int) $connection->id, $firstReference, $observedAt);
    $second = $identities->resolveOrCreateIdentity((int) $connection->id, $secondReference, $observedAt);

    expect($retry->id)->toBe($first->id)
        ->and($retry->workforce_entity_id)->toBe($first->workforce_entity_id)
        ->and(WorkforceEntity::query()->forTenant((int) $tenantA->id)->count())->toBe(2)
        ->and(WorkforceSnapshot::query()->forTenant((int) $tenantA->id)->where('event_type', 'identity_attached')->count())->toBe(2);

    expect(fn () => $identities->resolveOrCreateIdentity(
        (int) $connection->id,
        $firstReference,
        $observedAt,
        preferredEntityId: (int) $second->workforce_entity_id,
        provenance: new WorkforceProvenance('manual_review', 'collision-test'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'already bound');

    expect(fn () => app(WorkforceHistory::class)->record(
        $connection,
        WorkforceEntity::query()->findOrFail($first->workforce_entity_id),
        $second,
        'invalid_provenance',
        $observedAt,
        $observedAt,
        [],
        new WorkforceProvenance('negative_test'),
    ))->toThrow(WorkforceHistoryConflictException::class, 'one tenant, connection, identity, and entity');

    $secondConnection = app(ProviderConnectionStore::class)->configure(ProviderScope::tenant(), 'provider.two');
    app(ProviderConnectionStore::class)->activate((int) $secondConnection->id);
    expect(fn () => $identities->resolveOrCreateIdentity(
        (int) $secondConnection->id,
        connectorPersistenceReference(WorkforceResourceType::Employee, 'OTHER-EMP-001', 'provider.two'),
        $observedAt,
        preferredEntityId: (int) $first->workforce_entity_id,
        provenance: new WorkforceProvenance('provider_replacement'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'requires reviewed provenance');

    $replacementProviderIdentity = $identities->resolveOrCreateIdentity(
        (int) $secondConnection->id,
        connectorPersistenceReference(WorkforceResourceType::Employee, 'OTHER-EMP-001', 'provider.two'),
        $observedAt,
        preferredEntityId: (int) $first->workforce_entity_id,
        provenance: new WorkforceProvenance('provider_replacement', 'provider-replacement-approved'),
    );

    expect($replacementProviderIdentity->workforce_entity_id)->toBe($first->workforce_entity_id);

    app(TenantContext::class)->set((int) $tenantB->id);
    expect(fn () => $identities->resolve((int) $connection->id, $firstReference))
        ->toThrow(ConnectorRecordNotFoundException::class);
});

test('remap merge and deactivate preserve identity and provenance history', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Lifecycle Tenant']);
    $connection = connectorPersistenceConnection((int) $tenant->id);
    $identities = app(WorkforceIdentityStore::class);
    $projections = app(WorkforceProjectionStore::class);
    $occurredAt = new DateTimeImmutable('2026-08-30T09:00:00+00:00');
    $companyReference = connectorPersistenceReference(WorkforceResourceType::Company, 'COMPANY-LIFECYCLE');
    $old = connectorPersistenceReference(WorkforceResourceType::Employee, 'LEGACY-17');
    $replacement = connectorPersistenceReference(WorkforceResourceType::Employee, 'CURRENT-17');
    $survivor = connectorPersistenceReference(WorkforceResourceType::Employee, 'CURRENT-18');

    $oldIdentity = $identities->resolveOrCreateIdentity(
        (int) $connection->id,
        $old,
        $occurredAt,
        provenance: new WorkforceProvenance('migration_import', 'approved-remap'),
    );
    $projections->upsert((int) $connection->id, new WorkforceCompany(
        $companyReference,
        'Lifecycle Company',
        true,
        $occurredAt,
    ));
    $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $old,
        $companyReference,
        'Legacy Employee',
        true,
        $occurredAt,
        $occurredAt,
    ));

    expect(fn () => $identities->remap(
        (int) $connection->id,
        $old,
        $replacement,
        $occurredAt->modify('+1 hour'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'requires a review reference');

    expect(fn () => $identities->remap(
        (int) $connection->id,
        $old,
        $replacement,
        $occurredAt->modify('+1 hour'),
        new WorkforceProvenance('identity_remap'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'requires a review reference');

    expect(fn () => $identities->remap(
        (int) $connection->id,
        $old,
        $replacement,
        $occurredAt->modify('-1 minute'),
        new WorkforceProvenance('identity_remap', 'migration-team'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'cannot predate');

    $replacementIdentity = $identities->remap(
        (int) $connection->id,
        $old,
        $replacement,
        $occurredAt->modify('+1 hour'),
        new WorkforceProvenance('identity_remap', 'migration-team'),
    );
    $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $replacement,
        $companyReference,
        'Current Employee 17',
        true,
        $occurredAt,
        $occurredAt->modify('+90 minutes'),
    ));

    expect($oldIdentity->refresh()->state)->toBe(ExternalIdentity::STATE_REMAPPED)
        ->and($oldIdentity->replaced_by_identity_id)->toBe($replacementIdentity->id)
        ->and($replacementIdentity->workforce_entity_id)->toBe($oldIdentity->workforce_entity_id)
        ->and(ExternalIdentity::query()->forTenant((int) $tenant->id)->count())->toBe(3);

    $survivingIdentity = $identities->resolveOrCreateIdentity(
        (int) $connection->id,
        $survivor,
        $occurredAt->modify('+2 hours'),
    );
    $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $survivor,
        $companyReference,
        'Current Employee 18',
        true,
        $occurredAt,
        $occurredAt->modify('+2 hours'),
    ));
    expect(fn () => $identities->merge(
        (int) $connection->id,
        $replacement,
        $survivor,
        $occurredAt->modify('+3 hours'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'require a review reference');

    expect(fn () => $identities->merge(
        (int) $connection->id,
        $replacement,
        $survivor,
        $occurredAt->modify('+3 hours'),
        new WorkforceProvenance('identity_merge'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'require a review reference');

    expect(fn () => $identities->merge(
        (int) $connection->id,
        $replacement,
        $survivor,
        $occurredAt->modify('+105 minutes'),
        new WorkforceProvenance('identity_merge', 'duplicate-confirmed'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'cannot predate');

    $merged = $identities->merge(
        (int) $connection->id,
        $replacement,
        $survivor,
        $occurredAt->modify('+3 hours'),
        new WorkforceProvenance('identity_merge', 'duplicate-confirmed'),
    );

    expect($merged->id)->toBe($survivingIdentity->workforce_entity_id)
        ->and($identities->resolve((int) $connection->id, $old)->id)->toBe($merged->id)
        ->and($identities->resolve((int) $connection->id, $replacement)->id)->toBe($merged->id)
        ->and(WorkforceEntity::query()->findOrFail($oldIdentity->workforce_entity_id)->state)->toBe(WorkforceEntity::STATE_MERGED)
        ->and(WorkforceEmployeeProjection::query()->where('workforce_entity_id', $oldIdentity->workforce_entity_id)->value('active'))->toBeFalse()
        ->and(WorkforceEmployeeProjection::query()->where('workforce_entity_id', $survivingIdentity->workforce_entity_id)->value('active'))->toBeTrue();

    expect(fn () => $identities->deactivate(
        (int) $connection->id,
        $survivor,
        $occurredAt->modify('+4 hours'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'requires source provenance');

    expect(fn () => $identities->deactivate(
        (int) $connection->id,
        $survivor,
        $occurredAt->modify('+1 hour'),
        new WorkforceProvenance('provider_change_feed', correlationReference: 'event-123'),
    ))->toThrow(ExternalIdentityCollisionException::class, 'cannot predate');

    $identities->deactivate(
        (int) $connection->id,
        $survivor,
        $occurredAt->modify('+4 hours'),
        new WorkforceProvenance('provider_change_feed', correlationReference: 'event-123'),
    );

    expect($merged->refresh()->state)->toBe(WorkforceEntity::STATE_INACTIVE)
        ->and(ExternalIdentity::query()->forTenant((int) $tenant->id)->count())->toBe(4)
        ->and(WorkforceEmployeeProjection::query()->where('workforce_entity_id', $survivingIdentity->workforce_entity_id)->value('active'))->toBeFalse()
        ->and(WorkforceSnapshot::query()->forTenant((int) $tenant->id)->pluck('event_type')->all())
        ->toContain('identity_remapped', 'entity_merged', 'identity_deactivated');
});

test('typed workforce projections retain effective and observed facts without regressing on late input', function (): void {
    [$tenant] = createTenantWithCompany(['name' => 'Projection Tenant']);
    $connection = connectorPersistenceConnection((int) $tenant->id);
    $projections = app(WorkforceProjectionStore::class);
    $effectiveAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
    $observedAt = new DateTimeImmutable('2026-08-30T10:00:00+00:00');
    $companyReference = connectorPersistenceReference(WorkforceResourceType::Company, 'COMPANY-01');
    $organizationReference = connectorPersistenceReference(WorkforceResourceType::OrganizationUnit, 'ORG-PRODUCTION');
    $positionReference = connectorPersistenceReference(WorkforceResourceType::Position, 'POSITION-SUPERVISOR');
    $employeeReference = connectorPersistenceReference(WorkforceResourceType::Employee, 'EMP-100');
    $managerReference = connectorPersistenceReference(WorkforceResourceType::Employee, 'EMP-099');

    $company = $projections->upsert((int) $connection->id, new WorkforceCompany(
        $companyReference,
        'Example Manufacturing',
        true,
        $observedAt,
        'EXM',
        'company-v1',
    ));
    $organization = $projections->upsert((int) $connection->id, new WorkforceOrganizationUnit(
        $organizationReference,
        $companyReference,
        'Production',
        true,
        $effectiveAt,
        $observedAt,
        code: 'PROD',
        sourceVersion: 'org-v1',
    ));
    $position = $projections->upsert((int) $connection->id, new WorkforcePosition(
        $positionReference,
        $companyReference,
        'Production Supervisor',
        true,
        $effectiveAt,
        $observedAt,
        organizationReference: $organizationReference,
        code: 'PROD-SUP',
        tier: 'supervisor',
        sourceVersion: 'position-v1',
    ));
    $employee = $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $employeeReference,
        $companyReference,
        'Aminah Example',
        true,
        $effectiveAt,
        $observedAt,
        employeeNumber: 'E100',
        email: 'aminah@example.test',
        organizationReference: $organizationReference,
        positionReference: $positionReference,
        managerReference: $managerReference,
        sourceVersion: 'employee-v1',
    ), new WorkforceProvenance('provider_fixture'));

    expect($organization->company_entity_id)->toBe($company->workforce_entity_id)
        ->and($position->organization_entity_id)->toBe($organization->workforce_entity_id)
        ->and($employee->position_entity_id)->toBe($position->workforce_entity_id)
        ->and($employee->effective_at->toIso8601String())->toBe('2026-08-01T00:00:00+00:00')
        ->and($employee->observed_at->toIso8601String())->toBe('2026-08-30T10:00:00+00:00');

    $newer = $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $employeeReference,
        $companyReference,
        'Aminah New Name',
        true,
        $effectiveAt->modify('+7 days'),
        $observedAt->modify('+2 days'),
        employeeNumber: 'E100',
        organizationReference: $organizationReference,
        positionReference: $positionReference,
        sourceVersion: 'employee-v2',
    ));
    $late = $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $employeeReference,
        $companyReference,
        'Stale Name',
        false,
        $effectiveAt->modify('+1 day'),
        $observedAt->modify('+1 day'),
        employeeNumber: 'E100',
        sourceVersion: 'employee-stale',
    ));

    expect($late->id)->toBe($newer->id)
        ->and($late->display_name)->toBe('Aminah New Name')
        ->and($late->active)->toBeTrue()
        ->and(WorkforceEmployeeProjection::query()->forTenant((int) $tenant->id)->count())->toBe(1)
        ->and(WorkforceSnapshot::query()->forTenant((int) $tenant->id)->where('event_type', 'projection_upserted')->count())->toBe(6);

    expect(fn () => $projections->upsert((int) $connection->id, new WorkforceEmployee(
        $employeeReference,
        $companyReference,
        'Conflicting Name',
        true,
        $effectiveAt->modify('+7 days'),
        $observedAt->modify('+2 days'),
        employeeNumber: 'E100',
        sourceVersion: 'employee-conflict',
    )))->toThrow(WorkforceProjectionConflictException::class, 'same provider observation time');
});

test('sync checkpoints advance only on complete pages and preserve an append-only version history', function (): void {
    [$tenantA] = createTenantWithCompany(['name' => 'Checkpoint Tenant A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Checkpoint Tenant B']);
    $connection = connectorPersistenceConnection((int) $tenantA->id);
    $checkpoints = app(SyncCheckpointStore::class);
    $asOf = new DateTimeImmutable('2026-08-30T11:00:00+00:00');
    $incomplete = new WorkforceChangePage([], $asOf, nextPageCursor: 'page-2');

    expect(fn () => $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $incomplete,
        0,
    ))->toThrow(SyncCheckpointConflictException::class, 'only after the final page');

    $firstPage = new WorkforceChangePage([], $asOf, resumeCursor: 'resume-1', complete: true);
    $first = $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $firstPage,
        0,
        $asOf->modify('+1 minute'),
    );
    $retry = $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $firstPage,
        0,
        $asOf->modify('+2 minutes'),
    );

    expect($first->version)->toBe(1)
        ->and($retry->id)->toBe($first->id)
        ->and(SyncCheckpointEvent::query()->forTenant((int) $tenantA->id)->count())->toBe(1);

    $regressingPage = new WorkforceChangePage(
        [],
        $asOf->modify('-1 minute'),
        resumeCursor: 'resume-regression',
        complete: true,
    );
    expect(fn () => $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $regressingPage,
        1,
    ))->toThrow(SyncCheckpointConflictException::class, 'cannot move its provider as-of watermark backward');

    $secondPage = new WorkforceChangePage(
        [],
        $asOf->modify('+1 day'),
        resumeCursor: 'resume-2',
        complete: true,
    );

    expect(fn () => $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $secondPage,
        0,
    ))->toThrow(SyncCheckpointConflictException::class, 'expected 0, current 1');

    $second = $checkpoints->advanceCompletedPage(
        (int) $connection->id,
        'workforce.incremental',
        $secondPage,
        1,
    );

    expect($second->version)->toBe(2)
        ->and($second->resume_cursor)->toBe('resume-2')
        ->and(SyncCheckpointEvent::query()->forTenant((int) $tenantA->id)->pluck('version')->all())->toBe([1, 2]);

    app(TenantContext::class)->set((int) $tenantB->id);
    expect(fn () => $checkpoints->current((int) $connection->id, 'workforce.incremental'))
        ->toThrow(ConnectorRecordNotFoundException::class);
});

test('reconciliation issues are durable idempotent and tenant isolated', function (): void {
    [$tenantA] = createTenantWithCompany(['name' => 'Reconciliation Tenant A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Reconciliation Tenant B']);
    $connection = connectorPersistenceConnection((int) $tenantA->id);
    $issues = app(ReconciliationIssueStore::class);
    $firstSeen = new DateTimeImmutable('2026-08-30T12:00:00+00:00');
    $employeeIdentity = app(WorkforceIdentityStore::class)->resolveOrCreateIdentity(
        (int) $connection->id,
        connectorPersistenceReference(WorkforceResourceType::Employee, 'EMP-RECON'),
        $firstSeen,
    );

    expect(fn () => $issues->report(
        (int) $connection->id,
        'mismatched-resource:EMP-RECON',
        'invalid_reference',
        resourceType: WorkforceResourceType::Company->value,
        workforceEntityId: (int) $employeeIdentity->workforce_entity_id,
    ))->toThrow(InvalidReconciliationIssueException::class, 'does not match');

    $first = $issues->report(
        (int) $connection->id,
        'missing-manager:EMP-100',
        'missing_reference',
        new ReconciliationIssueDetails(field: 'manager', reasonCode: 'reference_missing'),
        WorkforceResourceType::Employee->value,
        'EMP-100',
        seenAt: $firstSeen,
    );
    $repeat = $issues->report(
        (int) $connection->id,
        'missing-manager:EMP-100',
        'missing_reference',
        new ReconciliationIssueDetails(field: 'manager', reasonCode: 'reference_missing', observedCount: 2),
        WorkforceResourceType::Employee->value,
        'EMP-100',
        severity: 'error',
        seenAt: $firstSeen->modify('+1 hour'),
    );

    expect($repeat->id)->toBe($first->id)
        ->and($repeat->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($repeat->last_seen_at->equalTo($firstSeen->modify('+1 hour')))->toBeTrue()
        ->and($repeat->details)->toBe([
            'field' => 'manager',
            'reason_code' => 'reference_missing',
            'observed_count' => 2,
        ])
        ->and(ReconciliationIssue::query()->forTenant((int) $tenantA->id)->count())->toBe(1);

    $resolved = $issues->resolve((int) $repeat->id, $firstSeen->modify('+2 hours'));
    expect($resolved->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and($issues->openForConnection((int) $connection->id))->toHaveCount(0);

    $staleReplay = $issues->report(
        (int) $connection->id,
        'missing-manager:EMP-100',
        'missing_reference',
        new ReconciliationIssueDetails(field: 'manager', reasonCode: 'reference_missing'),
        WorkforceResourceType::Employee->value,
        'EMP-100',
        seenAt: $firstSeen,
    );
    expect($staleReplay->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and($staleReplay->last_seen_at->equalTo($firstSeen->modify('+1 hour')))->toBeTrue();

    expect(fn () => $issues->report(
        (int) $connection->id,
        'missing-manager:EMP-100',
        'missing_reference',
        new ReconciliationIssueDetails(field: 'manager', reasonCode: 'reference_missing'),
        WorkforceResourceType::Employee->value,
        'EMP-100',
        seenAt: $firstSeen->modify('+3 hours'),
    ))->toThrow(ReconciliationIssueConflictException::class, 'explicit reopen');

    $recurrenceDetails = new ReconciliationIssueDetails(
        field: 'manager',
        reasonCode: 'reference_missing',
        observedCount: 3,
    );
    $reopened = $issues->reopen(
        (int) $repeat->id,
        $firstSeen->modify('+3 hours'),
        $recurrenceDetails,
    );
    $sameRecurrence = $issues->report(
        (int) $connection->id,
        'missing-manager:EMP-100',
        'missing_reference',
        $recurrenceDetails,
        WorkforceResourceType::Employee->value,
        'EMP-100',
        severity: 'error',
        seenAt: $firstSeen->modify('+3 hours'),
    );
    expect($reopened->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($sameRecurrence->id)->toBe($reopened->id);

    expect(fn () => $issues->report(
        (int) $connection->id,
        'invalid-resource',
        'missing_reference',
        resourceType: 'payroll_secret',
    ))->toThrow(InvalidReconciliationIssueException::class, 'resource type is invalid');

    expect(fn () => new ReconciliationIssueDetails(field: 'manager password'))
        ->toThrow(InvalidReconciliationIssueException::class, 'stable lowercase value');

    app(TenantContext::class)->set((int) $tenantB->id);
    expect(fn () => $issues->resolve((int) $repeat->id))
        ->toThrow(ConnectorRecordNotFoundException::class);
});
