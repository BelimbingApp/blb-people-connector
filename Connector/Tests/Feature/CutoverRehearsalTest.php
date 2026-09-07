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
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceFreshness;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\CutoverRehearsalService;
use App\Domains\PeopleConnector\Connector\Services\PrivacyDeletionService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed cutover and lives here.
 */

const CUTOVER_OLD_PROVIDER = 'test.cutover-old';

const CUTOVER_NEW_PROVIDER = 'test.cutover-new';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function cutoverAuthz(bool $allow): void
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
                throw new ProviderAuthorizationException(
                    providerId: 'connector',
                    operation: 'rehearse_cutover',
                    message: 'The actor lacks the connector connection management capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

function cutoverRef(string $provider, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($provider, $type, $id);
}

/**
 * A tenant mid-replacement: the old provider holds two employees, the new
 * connection is configured and active, and nothing has been mapped or synced
 * yet — which is every blocker at once.
 *
 * @return array{tenantId: int, companyId: int, oldId: int, newId: int, actor: Actor, at: DateTimeImmutable}
 */
function cutoverFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company((int) $company->id);

    $old = $store->configure($scope, CUTOVER_OLD_PROVIDER);
    $oldId = (int) $store->activate((int) $old->id)->id;

    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $companyRef = cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Company, 'CUT-CO');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($oldId, new WorkforceCompany($companyRef, 'Cutover Co', true, $at));

    foreach (['CUT-EMP-1', 'CUT-EMP-2'] as $externalId) {
        $projections->upsert($oldId, new WorkforceEmployee(
            reference: cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Employee, $externalId),
            companyReference: $companyRef,
            displayName: 'Person '.$externalId,
            active: true,
            effectiveAt: $at,
            observedAt: $at,
        ));
    }

    $new = $store->configure($scope, CUTOVER_NEW_PROVIDER);
    $newId = (int) $store->activate((int) $new->id)->id;

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'oldId' => $oldId,
        'newId' => $newId,
        'actor' => new Actor(PrincipalType::USER, 3001, (int) $company->id, tenantId: $tenantId),
        'at' => $at,
    ];
}

function cutoverMapAll(array $f): void
{
    app(ProviderReplacementService::class)->remap($f['actor'], $f['oldId'], $f['newId'], [
        new ProviderIdentityMapping(
            cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Employee, 'CUT-EMP-1'),
            cutoverRef(CUTOVER_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-1'),
        ),
        new ProviderIdentityMapping(
            cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Employee, 'CUT-EMP-2'),
            cutoverRef(CUTOVER_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-2'),
        ),
        new ProviderIdentityMapping(
            cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Company, 'CUT-CO'),
            cutoverRef(CUTOVER_NEW_PROVIDER, WorkforceResourceType::Company, 'NEW-CO'),
        ),
    ], 'cutover-review-2026-09-06');
}

function cutoverSyncTarget(array $f): void
{
    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $f['newId'],
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], new DateTimeImmutable, resumeCursor: 'cutover-ready', complete: true),
        0,
    );
}

test('a rehearsal reports every identity the target connection does not know', function (): void {
    $f = cutoverFixture('Cutover Unmapped Tenant');
    cutoverAuthz(true);

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Two employees and the company: nothing has been mapped, so the target
    // knows none of them.
    expect($report->unmappedIdentities)->toBe(3)
        ->and($report->blocked())->toBeTrue();
});

test('a rehearsal reports the target being unable to refresh what it takes over', function (): void {
    $f = cutoverFixture('Cutover Stale Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Everything is mapped, but the target has never completed a pass. Cutting
    // over now hands the workforce to a connection whose data is stale on
    // arrival.
    expect($report->unmappedIdentities)->toBe(0)
        ->and($report->targetStale)->toBeTrue()
        ->and($report->targetStaleReason)->toBe('never_synchronized')
        ->and($report->blocked())->toBeTrue();
});

test('a rehearsal reports open reconciliation issues on either connection', function (): void {
    $f = cutoverFixture('Cutover Open Issue Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    app(ReconciliationIssueStore::class)->report(
        $f['oldId'],
        'sync:employee:CUT-EMP-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'CUT-EMP-1',
    );

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // An unanswered question about the old provider does not become answerable
    // by switching providers; it becomes unanswerable.
    expect($report->openIssues)->toBe(1)
        ->and($report->blocked())->toBeTrue();
});

test('a rehearsal with nothing outstanding reports the cutover as clear', function (): void {
    $f = cutoverFixture('Cutover Clear Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    expect($report->unmappedIdentities)->toBe(0)
        ->and($report->targetStale)->toBeFalse()
        ->and($report->openIssues)->toBe(0)
        ->and($report->blocked())->toBeFalse();
});

test('a rehearsal writes nothing but its own audit row', function (): void {
    $f = cutoverFixture('Cutover Dry Run Tenant');
    cutoverAuthz(true);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert into|update|delete from)\s+"?([a-z0-9_]+)"?/i', $query->sql, $m) === 1) {
            $writes[] = strtolower($m[2]);
        }
    });

    app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // The word rehearsal is the promise. A dry run that touched anything would
    // be the one thing an operator ran it to avoid. The one row it does write
    // is the record that the read happened (#199), never workforce state.
    expect($writes)->toBe(['people_connector_connector_operator_audits']);
});

test('a rehearsal without the operator capability is refused', function (): void {
    $f = cutoverFixture('Cutover Denied Tenant');
    cutoverAuthz(false);

    expect(fn () => app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']))
        ->toThrow(ProviderAuthorizationException::class);
});

test('a rehearsal by an actor from another tenant is refused', function (): void {
    $f = cutoverFixture('Cutover Foreign Actor Tenant');
    cutoverAuthz(true);
    $outsider = new Actor(PrincipalType::USER, 3002, null, tenantId: $f['tenantId'] + 1);

    expect(fn () => app(CutoverRehearsalService::class)->rehearse($outsider, $f['oldId'], $f['newId']))
        ->toThrow(ProviderAuthorizationException::class);
});

test('a rehearsal naming another tenant\'s connection as the source is refused', function (): void {
    $foreign = cutoverFixture('Cutover Foreign Source Tenant');
    $f = cutoverFixture('Cutover Own Target Tenant');
    cutoverAuthz(true);

    // The actor is inside the current tenant; the request is not. A rehearsal
    // that read the other tenant's identities would report their cutover, and
    // the tenant filters below the locator would make it look clean instead.
    expect(fn () => app(CutoverRehearsalService::class)->rehearse($f['actor'], $foreign['oldId'], $f['newId']))
        ->toThrow(ConnectorRecordNotFoundException::class);

    expect(OperatorAudit::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('a rehearsal naming another tenant\'s connection as the target is refused', function (): void {
    $foreign = cutoverFixture('Cutover Foreign Target Tenant');
    $f = cutoverFixture('Cutover Own Source Tenant');
    cutoverAuthz(true);

    expect(fn () => app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $foreign['newId']))
        ->toThrow(ConnectorRecordNotFoundException::class);

    expect(OperatorAudit::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('a rehearsal against a retired target reports the retirement as a blocker', function (): void {
    $f = cutoverFixture('Cutover Retired Target Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['newId'], 'cutover-retire-2026-09-07');

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Everything is mapped and the target synced once before it was retired,
    // so on the watermark alone this cutover looks clear. A retired connection
    // can never refresh what it would take over; that is the blocker.
    expect($report->targetStale)->toBeTrue()
        ->and($report->targetStaleReason)->toBe(WorkforceFreshness::REASON_CONNECTION_INACTIVE)
        ->and($report->blocked())->toBeTrue()
        ->and($report->blockers())->toContain('the target connection is stale (connection_inactive)');
});

test('the command exits non-zero while a blocker stands and zero once it is clear', function (): void {
    $f = cutoverFixture('Cutover Command Tenant');
    cutoverAuthz(true);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);

    $this->artisan('people-connector:cutover-rehearsal', [
        'from' => $f['oldId'], 'to' => $f['newId'], '--tenant' => $f['tenantId'], '--as' => $operator->id,
    ])->assertExitCode(1);

    cutoverMapAll($f);
    cutoverSyncTarget($f);

    // Exit status is what a deployment script reads. A rehearsal that reported
    // blockers in prose and exited zero would be worse than not running.
    $this->artisan('people-connector:cutover-rehearsal', [
        'from' => $f['oldId'], 'to' => $f['newId'], '--tenant' => $f['tenantId'], '--as' => $operator->id,
    ])->assertExitCode(0);
});

test('an identity already deactivated on the source does not block the cutover', function (): void {
    $f = cutoverFixture('Cutover Departed Tenant');
    cutoverAuthz(true);
    app(WorkforceIdentityStore::class)->deactivate(
        $f['oldId'],
        cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Employee, 'CUT-EMP-2'),
        $f['at']->modify('+1 day'),
        new WorkforceProvenance('cutover.rehearsal.test', 'departure-2026-09-02'),
    );

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Someone who has already left does not need to survive the switch.
    // Counting them would send an operator hunting for a mapping that should
    // not exist.
    expect($report->unmappedIdentities)->toBe(2);
});

/**
 * The company reference each side names, once the company itself is mapped.
 *
 * Every projection hangs off a company, and the two providers spell that
 * company differently — which is the whole reason a cutover needs rehearsing.
 */
function cutoverCompanyRef(string $provider): ExternalReference
{
    return cutoverRef($provider, WorkforceResourceType::Company, $provider === CUTOVER_OLD_PROVIDER ? 'CUT-CO' : 'NEW-CO');
}

function cutoverEmployeeOn(array $f, string $provider, string $externalId, bool $active = true): void
{
    app(WorkforceProjectionStore::class)->upsert(
        $provider === CUTOVER_OLD_PROVIDER ? $f['oldId'] : $f['newId'],
        new WorkforceEmployee(
            reference: cutoverRef($provider, WorkforceResourceType::Employee, $externalId),
            companyReference: cutoverCompanyRef($provider),
            displayName: 'Person '.$externalId,
            active: $active,
            effectiveAt: $f['at'],
            observedAt: $f['at'],
        ),
    );
}

function cutoverOrganizationUnitOn(array $f, string $provider, string $externalId): void
{
    app(WorkforceProjectionStore::class)->upsert(
        $provider === CUTOVER_OLD_PROVIDER ? $f['oldId'] : $f['newId'],
        new WorkforceOrganizationUnit(
            reference: cutoverRef($provider, WorkforceResourceType::OrganizationUnit, $externalId),
            companyReference: cutoverCompanyRef($provider),
            name: 'Unit '.$externalId,
            active: true,
            effectiveAt: $f['at'],
            observedAt: $f['at'],
        ),
    );
}

/**
 * The company workforce entity the projections hang off.
 *
 * Not the platform company id: a projection's `company_entity_id` names the
 * workforce entity the connector minted for that company, which is what a
 * per-company count is grouped by.
 */
function cutoverCompanyEntityId(array $f): int
{
    return (int) ExternalIdentity::query()
        ->forTenant($f['tenantId'])
        ->where('connection_id', $f['oldId'])
        ->where('resource_type', WorkforceResourceType::Company->value)
        ->value('workforce_entity_id');
}

/** @return array<string, array{source: int, target: int}> keyed "type:company" */
function cutoverCounts(object $report): array
{
    $rows = [];

    foreach ($report->counts as $row) {
        $rows[$row->resourceType->value.':'.$row->companyEntityId] = [
            'source' => $row->sourceCount,
            'target' => $row->targetCount,
        ];
    }

    return $rows;
}

test('a cutover that is ready reports each side holding the same number', function (): void {
    $f = cutoverFixture('Cutover Counts Equal Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // A remap is what retires a source identity, so the side the source still
    // answers for has to include what it handed to this target — otherwise the
    // one moment a cutover is ready is the moment every count disagrees.
    expect(cutoverCounts($report))->toBe(['employee:'.cutoverCompanyEntityId($f) => ['source' => 2, 'target' => 2]])
        ->and($report->countMismatches())->toBe(0)
        ->and($report->blocked())->toBeFalse();
});

test('a target carrying somebody the source never had is a count mismatch', function (): void {
    $f = cutoverFixture('Cutover Counts Extra Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    cutoverEmployeeOn($f, CUTOVER_NEW_PROVIDER, 'NEW-EMP-3');

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Every identity maps and the target is fresh, so the three older checks
    // are all clear; only the totals say the target would arrive holding a
    // person the source never knew about.
    expect(cutoverCounts($report))->toBe(['employee:'.cutoverCompanyEntityId($f) => ['source' => 2, 'target' => 3]])
        ->and($report->unmappedIdentities)->toBe(0)
        ->and($report->targetStale)->toBeFalse()
        ->and($report->openIssues)->toBe(0)
        ->and($report->countMismatches())->toBe(1)
        ->and($report->blocked())->toBeTrue()
        ->and($report->blockers())->toContain('1 projection count mismatch(es)');
});

test('an organization unit the target does not have is a count mismatch of its own', function (): void {
    $f = cutoverFixture('Cutover Counts Missing Unit Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    cutoverOrganizationUnitOn($f, CUTOVER_OLD_PROVIDER, 'CUT-OU-1');

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Counted per resource type, not as one total: an operator told only that
    // "something is short by one" has to go and find out what.
    expect(cutoverCounts($report))->toBe([
        'employee:'.cutoverCompanyEntityId($f) => ['source' => 2, 'target' => 2],
        'organization_unit:'.cutoverCompanyEntityId($f) => ['source' => 1, 'target' => 0],
    ])
        ->and($report->countMismatches())->toBe(1)
        ->and($report->blockers())->toContain('1 projection count mismatch(es)');
});

test('a privacy-deleted person is excluded from both sides rather than from one', function (): void {
    $f = cutoverFixture('Cutover Counts Erased Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['newId'],
        cutoverRef(CUTOVER_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-2'),
        new WorkforceProvenance('cutover.rehearsal.test', 'erasure-2026-09-03'),
    );

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Erasure removes one row that both sides were counting. Filtering it on
    // one side only would report a cutover as losing somebody it never had.
    expect(cutoverCounts($report))->toBe(['employee:'.cutoverCompanyEntityId($f) => ['source' => 1, 'target' => 1]])
        ->and($report->countMismatches())->toBe(0);
});

test('an employee the source deactivated before the cutover is counted on neither side', function (): void {
    $f = cutoverFixture('Cutover Counts Departed Tenant');
    cutoverAuthz(true);
    app(WorkforceIdentityStore::class)->deactivate(
        $f['oldId'],
        cutoverRef(CUTOVER_OLD_PROVIDER, WorkforceResourceType::Employee, 'CUT-EMP-2'),
        $f['at']->modify('+1 day'),
        new WorkforceProvenance('cutover.rehearsal.test', 'departure-2026-09-02'),
    );

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // Somebody who has already left is not a shortfall on the target.
    expect(cutoverCounts($report))->toBe(['employee:'.cutoverCompanyEntityId($f) => ['source' => 1, 'target' => 0]]);
});

test("a sibling tenant's projections are never counted", function (): void {
    $f = cutoverFixture('Cutover Counts Mine Tenant');
    $other = cutoverFixture('Cutover Counts Theirs Tenant');
    cutoverAuthz(true);
    app(TenantContext::class)->set($other['tenantId']);
    cutoverEmployeeOn($other, CUTOVER_OLD_PROVIDER, 'CUT-EMP-3');
    app(TenantContext::class)->set($f['tenantId']);
    cutoverMapAll($f);
    cutoverSyncTarget($f);

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    // The sibling holds three where this tenant holds two. A count that
    // reached across the tenant would read four, or two against three.
    expect(cutoverCounts($report))->toBe(['employee:'.cutoverCompanyEntityId($f) => ['source' => 2, 'target' => 2]]);
});

test('the json report carries the counts per company and resource type', function (): void {
    $f = cutoverFixture('Cutover Counts Json Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);

    // Artisan::call, not $this->artisan: the pending-command helper keeps its
    // output to itself, and this test is about what the JSON actually says.
    $exitCode = Artisan::call('people-connector:cutover-rehearsal', [
        'from' => $f['oldId'], 'to' => $f['newId'], '--tenant' => $f['tenantId'],
        '--as' => $operator->id, '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);

    expect($payload['count_mismatches'])->toBe(0)
        ->and($payload['counts'])->toBe([[
            'company_entity_id' => cutoverCompanyEntityId($f),
            'resource_type' => 'employee',
            'source_count' => 2,
            'target_count' => 2,
        ]])
        ->and($payload['blocked'])->toBeFalse();
});

test('the printed report names the count table before showing it', function (): void {
    $f = cutoverFixture('Cutover Counts Table Tenant');
    cutoverAuthz(true);
    cutoverMapAll($f);
    cutoverSyncTarget($f);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);

    $this->artisan('people-connector:cutover-rehearsal', [
        'from' => $f['oldId'], 'to' => $f['newId'], '--tenant' => $f['tenantId'], '--as' => $operator->id,
    ])
        ->expectsOutputToContain('Live projections each connection can answer for, per company:')
        ->assertExitCode(0);
});
