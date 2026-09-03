<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;
use App\Domains\PeopleConnector\Connector\Exceptions\PrivacyDeletionException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use App\Domains\PeopleConnector\Connector\Services\PrivacyDeletionService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function privacyDeletionReference(
    WorkforceResourceType $type,
    string $externalId,
): ExternalReference {
    return new ExternalReference('test.people', $type, $externalId);
}

function privacyDeletionConnection(int $tenantId): ProviderConnection
{
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(
        ProviderScope::tenant(),
        'test.people',
        new ProviderConnectionMetadata(
            ProviderConnectionMode::RemoteHttp,
            'https://people.example.test',
            1001,
            2001,
        ),
        'Privacy People',
        '1.2.0',
        '1.0.0',
    );

    return $store->activate((int) $connection->id);
}

/**
 * @return array{0: int, 1: int, 2: int, 3: ProviderConnection}
 */
function privacyDeletionTwoCompanyFixture(): array
{
    [$tenant] = createTenantWithCompany(['name' => 'Privacy Tenant']);
    $connection = privacyDeletionConnection((int) $tenant->id);
    $identities = app(WorkforceIdentityStore::class);
    $projections = app(WorkforceProjectionStore::class);
    $at = new DateTimeImmutable('2026-09-03T04:00:00+00:00');

    $companyA = privacyDeletionReference(WorkforceResourceType::Company, 'PRIV-CO-A');
    $companyB = privacyDeletionReference(WorkforceResourceType::Company, 'PRIV-CO-B');
    $projections->upsert((int) $connection->id, new WorkforceCompany($companyA, 'Alpha Co', true, $at));
    $projections->upsert((int) $connection->id, new WorkforceCompany($companyB, 'Beta Co', true, $at));

    $companyAId = (int) $identities->resolve((int) $connection->id, $companyA)->id;
    $companyBId = (int) $identities->resolve((int) $connection->id, $companyB)->id;

    foreach ([
        [$companyA, $companyAId, 'A'],
        [$companyB, $companyBId, 'B'],
    ] as [$companyRef, $companyId, $suffix]) {
        $org = privacyDeletionReference(WorkforceResourceType::OrganizationUnit, "PRIV-ORG-{$suffix}");
        $pos = privacyDeletionReference(WorkforceResourceType::Position, "PRIV-POS-{$suffix}");
        $emp = privacyDeletionReference(WorkforceResourceType::Employee, "PRIV-EMP-{$suffix}");

        $projections->upsert((int) $connection->id, new WorkforceOrganizationUnit(
            $org,
            $companyRef,
            "Org {$suffix}",
            true,
            $at,
            $at,
            code: "ORG-{$suffix}",
        ));
        $projections->upsert((int) $connection->id, new WorkforcePosition(
            $pos,
            $companyRef,
            "Pos {$suffix}",
            true,
            $at,
            $at,
            organizationReference: $org,
            code: "POS-{$suffix}",
            tier: 'T1',
        ));
        $projections->upsert((int) $connection->id, new WorkforceEmployee(
            $emp,
            $companyRef,
            "Employee {$suffix}",
            true,
            $at,
            $at,
            employeeNumber: "E-{$suffix}",
            email: "emp.{$suffix}@example.test",
            organizationReference: $org,
            positionReference: $pos,
        ));
    }

    return [(int) $tenant->id, $companyAId, $companyBId, $connection];
}

test('company privacy deletion tombstones projections and redacts snapshots for that company only', function (): void {
    [$tenantId, $companyAId, $companyBId, $connection] = privacyDeletionTwoCompanyFixture();
    $privacy = app(PrivacyDeletionService::class);
    $erasedAt = new DateTimeImmutable('2026-09-03T05:00:00+00:00');

    $employeeA = WorkforceEmployeeProjection::query()->forCompany($tenantId, $companyAId)->firstOrFail();
    $employeeB = WorkforceEmployeeProjection::query()->forCompany($tenantId, $companyBId)->firstOrFail();

    expect(WorkforceSnapshot::query()->forTenant($tenantId)->where('workforce_entity_id', $employeeA->workforce_entity_id)->count())
        ->toBeGreaterThan(0)
        ->and($employeeA->email)->toBe('emp.A@example.test');

    $report = $privacy->eraseCompany($companyAId, $erasedAt);

    expect($report->companyEntityId)->toBe($companyAId)
        ->and($report->employeesTombstoned)->toBe(1)
        ->and($report->organizationUnitsTombstoned)->toBe(1)
        ->and($report->positionsTombstoned)->toBe(1)
        ->and($report->snapshotsRedacted)->toBeGreaterThan(0);

    $employeeA->refresh();
    $employeeB->refresh();
    $orgA = WorkforceOrganizationUnitProjection::query()->forCompany($tenantId, $companyAId)->firstOrFail();
    $orgB = WorkforceOrganizationUnitProjection::query()->forCompany($tenantId, $companyBId)->firstOrFail();
    $posA = WorkforcePositionProjection::query()->forCompany($tenantId, $companyAId)->firstOrFail();
    $posB = WorkforcePositionProjection::query()->forCompany($tenantId, $companyBId)->firstOrFail();

    expect($employeeA->display_name)->toBe('[redacted]')
        ->and($employeeA->email)->toBeNull()
        ->and($employeeA->employee_number)->toBeNull()
        ->and($employeeA->active)->toBeFalse()
        ->and($employeeA->privacy_deleted_at?->equalTo($erasedAt))->toBeTrue()
        ->and($orgA->name)->toBe('[redacted]')
        ->and($orgA->code)->toBeNull()
        ->and($orgA->privacy_deleted_at)->not->toBeNull()
        ->and($posA->name)->toBe('[redacted]')
        ->and($posA->tier)->toBeNull()
        ->and($posA->privacy_deleted_at)->not->toBeNull();

    expect($employeeB->display_name)->toBe('Employee B')
        ->and($employeeB->email)->toBe('emp.B@example.test')
        ->and($employeeB->privacy_deleted_at)->toBeNull()
        ->and($orgB->name)->toBe('Org B')
        ->and($orgB->privacy_deleted_at)->toBeNull()
        ->and($posB->name)->toBe('Pos B')
        ->and($posB->privacy_deleted_at)->toBeNull();

    $snapshotA = WorkforceSnapshot::query()
        ->forTenant($tenantId)
        ->where('workforce_entity_id', $employeeA->workforce_entity_id)
        ->whereNotNull('redacted_at')
        ->firstOrFail();

    expect($snapshotA->payload['redacted'] ?? false)->toBeTrue()
        ->and($snapshotA->redacted_at)->not->toBeNull();

    $snapshotB = WorkforceSnapshot::query()
        ->forTenant($tenantId)
        ->where('workforce_entity_id', $employeeB->workforce_entity_id)
        ->firstOrFail();

    expect($snapshotB->redacted_at)->toBeNull()
        ->and($snapshotB->payload['redacted'] ?? false)->toBeFalse();

    // Append-only still refuses ordinary mutation and deletion after redaction.
    expect(fn () => $snapshotA->forceFill(['event_type' => 'tampered'])->save())
        ->toThrow(AppendOnlyRecordException::class)
        ->and(fn () => $snapshotA->delete())
        ->toThrow(AppendOnlyRecordException::class);

    // Idempotent second pass does not re-count already tombstoned rows.
    $second = $privacy->eraseCompany($companyAId, $erasedAt->modify('+1 hour'));
    expect($second->employeesTombstoned)->toBe(0)
        ->and($second->organizationUnitsTombstoned)->toBe(0)
        ->and($second->positionsTombstoned)->toBe(0)
        ->and($second->snapshotsRedacted)->toBe(0);

    unset($connection);
});

test('privacy deletion refuses a non-company workforce entity', function (): void {
    [$tenantId, $companyAId] = privacyDeletionTwoCompanyFixture();
    $employee = WorkforceEmployeeProjection::query()->forCompany($tenantId, $companyAId)->firstOrFail();

    expect(fn () => app(PrivacyDeletionService::class)->eraseCompany((int) $employee->workforce_entity_id))
        ->toThrow(PrivacyDeletionException::class, 'company workforce entity');
});
