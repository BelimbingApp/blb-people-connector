<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionRetirementException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidWorkforceProvenanceException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\SyncCheckpointStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper is prefixed retirement and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany().
 */

const RETIREMENT_PROVIDER = 'test.retirement';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function retirementAuthz(bool $allow): void
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
                    operation: 'retire_connection',
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

function retirementRef(WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference(RETIREMENT_PROVIDER, $type, $id);
}

/** @return array{tenantId: int, connectionId: int, actor: Actor, at: DateTimeImmutable} */
function retirementFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), RETIREMENT_PROVIDER);
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $companyRef = retirementRef(WorkforceResourceType::Company, 'RET-CO');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($connectionId, new WorkforceCompany($companyRef, 'Retiring Co', true, $at));
    $projections->upsert($connectionId, new WorkforceEmployee(
        reference: retirementRef(WorkforceResourceType::Employee, 'RET-EMP-1'),
        companyReference: $companyRef,
        displayName: 'Ada Retiring',
        active: true,
        effectiveAt: $at,
        observedAt: $at,
    ));

    app(SyncCheckpointStore::class)->advanceCompletedPage(
        $connectionId,
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $at, resumeCursor: 'before-retirement', complete: true),
        0,
        $at,
    );

    return [
        'tenantId' => $tenantId,
        'connectionId' => $connectionId,
        'actor' => new Actor(PrincipalType::USER, 5001, (int) $company->id, tenantId: $tenantId),
        'at' => $at,
    ];
}

function retirementOpenIssue(int $connectionId): void
{
    app(ReconciliationIssueStore::class)->report(
        $connectionId,
        'sync:employee:RET-EMP-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'RET-EMP-1',
    );
}

test('retiring a connection freezes it without erasing what it recorded', function (): void {
    $f = retirementFixture('Retirement Happy Tenant');
    retirementAuthz(true);
    $identitiesBefore = ExternalIdentity::query()->forTenant($f['tenantId'])->count();

    $retired = app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'retirement-2026-09-06',
    );

    expect($retired->connection->status)->toBe(ProviderConnection::STATUS_RETIRED)
        ->and(ExternalIdentity::query()->forTenant($f['tenantId'])->count())->toBe($identitiesBefore)
        ->and((int) app(WorkforceIdentityStore::class)->resolve(
            $f['connectionId'],
            retirementRef(WorkforceResourceType::Employee, 'RET-EMP-1'),
        )->id)->toBeGreaterThan(0);
});

test('a retired connection refuses further projection writes', function (): void {
    $f = retirementFixture('Retirement Read Only Tenant');
    retirementAuthz(true);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');

    // History, not a live feed. A write here would rewrite the past under an
    // operator who was told this connection was finished.
    expect(fn () => app(WorkforceProjectionStore::class)->upsert($f['connectionId'], new WorkforceEmployee(
        reference: retirementRef(WorkforceResourceType::Employee, 'RET-EMP-1'),
        companyReference: retirementRef(WorkforceResourceType::Company, 'RET-CO'),
        displayName: 'Ada Changed',
        active: true,
        effectiveAt: $f['at']->modify('+1 day'),
        observedAt: $f['at']->modify('+1 day'),
    )))->toThrow(ConnectionRetirementException::class);
});

test('a retired connection freezes its checkpoint', function (): void {
    $f = retirementFixture('Retirement Frozen Checkpoint Tenant');
    retirementAuthz(true);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');

    expect(fn () => app(SyncCheckpointStore::class)->advanceCompletedPage(
        $f['connectionId'],
        WorkforceFreshnessPolicy::stream(),
        new WorkforceChangePage([], $f['at']->modify('+1 day'), resumeCursor: 'after-retirement', complete: true),
        1,
        $f['at']->modify('+1 day'),
    ))->toThrow(ConnectionRetirementException::class);
});

test('a retired connection cannot be brought back by activating it again', function (): void {
    $f = retirementFixture('Retirement Final Tenant');
    retirementAuthz(true);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');

    // Retirement that a single activate() undoes is not retirement. Coming back
    // means configuring a new connection, which is a decision with its own
    // provider replacement behind it.
    expect(fn () => app(ProviderConnectionStore::class)->activate($f['connectionId']))
        ->toThrow(ConnectionRetirementException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_RETIRED);
});

test('retirement is refused while a reconciliation issue on that connection is still open', function (): void {
    $f = retirementFixture('Retirement Open Issue Tenant');
    retirementAuthz(true);
    retirementOpenIssue($f['connectionId']);

    // Retiring underneath an open issue would strand the operator: the queue
    // entry survives, and every route to acting on it has just been frozen.
    expect(fn () => app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'retirement-2026-09-06',
    ))->toThrow(ConnectionRetirementException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_ACTIVE);
});

test('a resolved reconciliation issue does not block retirement', function (): void {
    $f = retirementFixture('Retirement Resolved Issue Tenant');
    retirementAuthz(true);
    retirementOpenIssue($f['connectionId']);
    $issue = app(ReconciliationIssueStore::class)->openForConnection($f['connectionId'])->first();
    app(ReconciliationIssueStore::class)->resolve((int) $issue->id);

    $retired = app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');

    expect($retired->connection->status)->toBe(ProviderConnection::STATUS_RETIRED);
});

test('retirement without the operator capability is refused', function (): void {
    $f = retirementFixture('Retirement Denied Tenant');
    retirementAuthz(false);

    expect(fn () => app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'retirement-2026-09-06',
    ))->toThrow(ProviderAuthorizationException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_ACTIVE);
});

test('retirement by an actor from another tenant is refused', function (): void {
    $f = retirementFixture('Retirement Foreign Actor Tenant');
    retirementAuthz(true);
    $outsider = new Actor(PrincipalType::USER, 5002, null, tenantId: $f['tenantId'] + 1);

    expect(fn () => app(ConnectionRetirementService::class)->retire(
        $outsider,
        $f['connectionId'],
        'retirement-2026-09-06',
    ))->toThrow(ProviderAuthorizationException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_ACTIVE);
});

test('retirement requires a review reference', function (): void {
    $f = retirementFixture('Retirement Unreviewed Tenant');
    retirementAuthz(true);

    expect(fn () => app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], '   '))
        ->toThrow(ConnectionRetirementException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_ACTIVE);
});

test('retiring an already retired connection is refused rather than repeated', function (): void {
    $f = retirementFixture('Retirement Twice Tenant');
    retirementAuthz(true);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');

    expect(fn () => app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'retirement-2026-09-07',
    ))->toThrow(ConnectionRetirementException::class);
});

test('the retirement report carries the review reference that authorized it', function (): void {
    $f = retirementFixture('Retirement Provenance Tenant');
    retirementAuthz(true);

    $report = app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'retirement-2026-09-06',
    );

    // A required review reference that evaporates is not an audit trail. A
    // caller has to be able to prove which reference authorized this, the way
    // ProviderReplacementService already reports its own.
    expect($report->reviewReference)->toBe('retirement-2026-09-06')
        ->and($report->connectionId)->toBe($f['connectionId'])
        ->and($report->provenance()->source)->toBe('connection.retirement')
        ->and($report->provenance()->reviewReference)->toBe('retirement-2026-09-06');
});

test('a review reference that is not an opaque identifier is refused', function (): void {
    $f = retirementFixture('Retirement Bad Reference Tenant');
    retirementAuthz(true);

    // WorkforceProvenance already defines what a reference may look like.
    // Accepting anything non-empty here would let prose, or a secret, into the
    // one field meant to be quotable back to an operator.
    expect(fn () => app(ConnectionRetirementService::class)->retire(
        $f['actor'],
        $f['connectionId'],
        'approved by Dana over lunch',
    ))->toThrow(InvalidWorkforceProvenanceException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('status'))
        ->toBe(ProviderConnection::STATUS_ACTIVE);
});

test('reconfiguring a retired connection is refused rather than rewriting frozen metadata', function (): void {
    $f = retirementFixture('Retirement Reconfigure Tenant');
    retirementAuthz(true);
    $company = (int) ProviderConnection::query()->whereKey($f['connectionId'])->value('company_id');
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-06');
    $labelBefore = ProviderConnection::query()->whereKey($f['connectionId'])->value('label');

    // The schema allows one row per (tenant, scope, provider), so configure()
    // finds the retired row rather than making a second one. Letting it through
    // would rewrite the label and versions of a connection whose history is
    // supposed to be frozen.
    expect(fn () => app(ProviderConnectionStore::class)->configure(
        ProviderScope::company($company),
        RETIREMENT_PROVIDER,
        label: 'Renamed after retirement',
    ))->toThrow(ConnectionRetirementException::class);

    expect(ProviderConnection::query()->whereKey($f['connectionId'])->value('label'))->toBe($labelBefore);
});
