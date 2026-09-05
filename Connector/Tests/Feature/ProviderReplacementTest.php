<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderReplacementException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed replacement and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany().
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const REPLACEMENT_OLD_PROVIDER = 'test.replaced';

const REPLACEMENT_NEW_PROVIDER = 'test.replacement';

function replacementRef(string $providerId, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($providerId, $type, $id);
}

function replacementAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

/**
 * A tenant whose company-scoped connection has been replaced: the old provider
 * holds two employees and a company, and the new provider's connection has just
 * been activated, which switched the old one off the way activate() does.
 *
 * @return array{tenantId: int, companyId: int, oldConnectionId: int, newConnectionId: int, entityIds: array<string, int>}
 */
function replacementFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company((int) $company->id);

    $old = $store->configure($scope, REPLACEMENT_OLD_PROVIDER);
    $oldConnectionId = (int) $store->activate((int) $old->id)->id;

    $at = replacementAt('2026-09-01T08:00:00+00:00');
    $projections = app(WorkforceProjectionStore::class);
    $companyRef = replacementRef(REPLACEMENT_OLD_PROVIDER, WorkforceResourceType::Company, 'OLD-CO');
    $projections->upsert($oldConnectionId, new WorkforceCompany($companyRef, 'Replaced Co', true, $at));

    foreach (['OLD-EMP-1' => 'Ada Old', 'OLD-EMP-2' => 'Bo Old'] as $externalId => $displayName) {
        $projections->upsert($oldConnectionId, new WorkforceEmployee(
            reference: replacementRef(REPLACEMENT_OLD_PROVIDER, WorkforceResourceType::Employee, $externalId),
            companyReference: $companyRef,
            displayName: $displayName,
            active: true,
            effectiveAt: $at,
            observedAt: $at,
        ));
    }

    $identities = app(WorkforceIdentityStore::class);
    $entityIds = [];
    foreach (['OLD-EMP-1', 'OLD-EMP-2'] as $externalId) {
        $entityIds[$externalId] = (int) $identities->resolve(
            $oldConnectionId,
            replacementRef(REPLACEMENT_OLD_PROVIDER, WorkforceResourceType::Employee, $externalId),
        )->id;
    }

    // Activating the replacement switches the old connection off, which is what
    // makes this a replacement rather than two providers running at once.
    $new = $store->configure($scope, REPLACEMENT_NEW_PROVIDER);
    $newConnectionId = (int) $store->activate((int) $new->id)->id;

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'oldConnectionId' => $oldConnectionId,
        'newConnectionId' => $newConnectionId,
        'entityIds' => $entityIds,
    ];
}

function replacementMapping(string $from, string $to): ProviderIdentityMapping
{
    return new ProviderIdentityMapping(
        replacementRef(REPLACEMENT_OLD_PROVIDER, WorkforceResourceType::Employee, $from),
        replacementRef(REPLACEMENT_NEW_PROVIDER, WorkforceResourceType::Employee, $to),
    );
}

function replacementIdentity(int $tenantId, int $connectionId, string $externalId): ?ExternalIdentity
{
    return ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->where('external_id', $externalId)
        ->first();
}

test('a reviewed replacement rebinds each identity to the new connection and keeps the workforce entity id', function (): void {
    $f = replacementFixture('Replacement Happy Tenant');

    $report = app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1'), replacementMapping('OLD-EMP-2', 'NEW-EMP-2')],
        'replacement-2026-09-06',
    );

    $identities = app(WorkforceIdentityStore::class);

    expect($report->remapped)->toBe(2)
        ->and((int) $identities->resolve($f['newConnectionId'], replacementRef(REPLACEMENT_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-1'))->id)
        ->toBe($f['entityIds']['OLD-EMP-1'])
        ->and((int) $identities->resolve($f['newConnectionId'], replacementRef(REPLACEMENT_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-2'))->id)
        ->toBe($f['entityIds']['OLD-EMP-2'])
        ->and(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_REMAPPED);
});

test('a replacement without a review reference is refused', function (): void {
    $f = replacementFixture('Replacement Unapproved Tenant');

    expect(fn () => app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1')],
        '   ',
    ))->toThrow(ProviderReplacementException::class);

    expect(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('a replacement onto a connection in another tenant is refused', function (): void {
    $f = replacementFixture('Replacement Isolation Tenant');
    $other = replacementFixture('Replacement Other Tenant');
    app(TenantContext::class)->set($f['tenantId']);

    expect(fn () => app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $other['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1')],
        'replacement-2026-09-06',
    ))->toThrow(ConnectorRecordNotFoundException::class);

    expect(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('a mapping that sends one identity to two references is refused', function (): void {
    $f = replacementFixture('Replacement One To Many Tenant');

    expect(fn () => app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1'), replacementMapping('OLD-EMP-1', 'NEW-EMP-2')],
        'replacement-2026-09-06',
    ))->toThrow(ProviderReplacementException::class);

    expect(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('a mapping that sends two identities to the same reference is refused', function (): void {
    $f = replacementFixture('Replacement Many To One Tenant');

    expect(fn () => app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1'), replacementMapping('OLD-EMP-2', 'NEW-EMP-1')],
        'replacement-2026-09-06',
    ))->toThrow(ProviderReplacementException::class);

    expect(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-2')?->state)
        ->toBe(ExternalIdentity::STATE_ACTIVE);
});

test('an unknown source identity refuses the whole replacement and applies none of it', function (): void {
    $f = replacementFixture('Replacement Atomicity Tenant');

    expect(fn () => app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1'), replacementMapping('OLD-NOBODY', 'NEW-EMP-2')],
        'replacement-2026-09-06',
    ))->toThrow(ConnectorRecordNotFoundException::class);

    // The first pair was good. A partly-applied replacement would leave the
    // operator with half a provider migration and no way to tell which half.
    expect(replacementIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_ACTIVE)
        ->and(replacementIdentity($f['tenantId'], $f['newConnectionId'], 'NEW-EMP-1'))->toBeNull();
});

test('each remap writes an audit row naming both references', function (): void {
    $f = replacementFixture('Replacement Audit Tenant');

    app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1')],
        'replacement-2026-09-06',
    );

    $audit = DB::table('people_connector_connector_workforce_snapshots')
        ->where('workforce_entity_id', $f['entityIds']['OLD-EMP-1'])
        ->where('event_type', 'identity_handed_over')
        ->get();

    expect($audit)->toHaveCount(1)
        ->and(json_decode((string) $audit->first()->payload, true))
        ->toMatchArray([
            'external_id' => 'OLD-EMP-1',
            'replacement_external_id' => 'NEW-EMP-1',
            'replacement_provider_id' => REPLACEMENT_NEW_PROVIDER,
        ]);
});

test('a replacement writes to no table the connector does not own', function (): void {
    $f = replacementFixture('Replacement Boundary Tenant');
    $written = [];
    DB::listen(function ($query) use (&$written): void {
        if (preg_match('/^\s*(insert into|update|delete from)\s+"?([a-z0-9_]+)"?/i', $query->sql, $m) === 1) {
            $written[] = strtolower($m[2]);
        }
    });

    app(ProviderReplacementService::class)->remap(
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [replacementMapping('OLD-EMP-1', 'NEW-EMP-1')],
        'replacement-2026-09-06',
    );

    // People owns the business records that reference these entities. The whole
    // point of keeping the entity id is that People never has to be touched.
    $foreign = array_values(array_unique(array_filter(
        $written,
        static fn (string $table): bool => ! str_starts_with($table, 'people_connector_'),
    )));

    expect($written)->not->toBeEmpty()
        ->and($foreign)->toBe([]);
});
