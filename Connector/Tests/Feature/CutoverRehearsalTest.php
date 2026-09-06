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
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\CutoverRehearsalService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
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
