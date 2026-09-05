<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed retention and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany().
 */

const RETENTION_ISSUES_TABLE = 'people_connector_connector_reconciliation_issues';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function retentionAuthz(bool $allow): void
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
                    operation: 'review_retention',
                    message: 'The actor lacks the connector retention review capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/**
 * One tenant with an active connection and two reconciliation issues: one first
 * seen well past any sane retention window, one seen today.
 *
 * @return array{tenantId: int, connectionId: int, actor: Actor}
 */
function retentionTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), 'test.retention');
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    $issues = app(ReconciliationIssueStore::class);
    $issues->report(
        $connectionId,
        'sync:employee:OLD',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'OLD',
        seenAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
    );
    $issues->report(
        $connectionId,
        'sync:employee:NEW',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'NEW',
        seenAt: new DateTimeImmutable('2026-09-06T00:00:00+00:00'),
    );

    return [
        'tenantId' => $tenantId,
        'connectionId' => $connectionId,
        'actor' => new Actor(PrincipalType::USER, 9001, (int) $company->id, tenantId: $tenantId),
    ];
}

function retentionConfigure(array $tables): void
{
    config()->set('people-connector.retention', $tables);
}

test('the report counts only the rows past retention for a configured table', function (): void {
    $f = retentionTenant('Retention Counting Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->tenantId)->toBe($f['tenantId'])
        ->and($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(1)
        ->and($report->totalExpired())->toBe(1);
});

test('a table configured as indefinite is reported as retained forever rather than counted', function (): void {
    $f = retentionTenant('Retention Indefinite Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => null, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->isIndefinite(RETENTION_ISSUES_TABLE))->toBeTrue()
        ->and($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(0)
        ->and($report->totalExpired())->toBe(0);
});

test('a retention window wide enough to cover every row counts nothing', function (): void {
    $f = retentionTenant('Retention Wide Window Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 36_500, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(0);
});

test('the report counts only the current tenant rows', function (): void {
    $mine = retentionTenant('Retention Mine Tenant');
    retentionTenant('Retention Theirs Tenant');
    app(TenantContext::class)->set($mine['tenantId']);
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($mine['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    // Both tenants have exactly one row past retention. A report that counted
    // two would be telling this operator about somebody else's data.
    expect($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(1);
});

test('a caller without the retention capability is refused', function (): void {
    $f = retentionTenant('Retention Denied Tenant');
    retentionAuthz(false);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(ProviderAuthorizationException::class);
});

test('an actor from outside the current tenant is refused', function (): void {
    $f = retentionTenant('Retention Foreign Actor Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $outsider = new Actor(PrincipalType::USER, 9002, null, tenantId: $f['tenantId'] + 1);

    expect(fn () => app(RetentionPolicy::class)->review($outsider, new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(ProviderAuthorizationException::class);
});

test('a retention entry naming a table the connector does not own is refused', function (): void {
    $f = retentionTenant('Retention Foreign Table Tenant');
    retentionAuthz(true);
    retentionConfigure(['users' => ['days' => 365, 'column' => 'created_at']]);

    // Retention is a statement about connector-owned rows. A policy that could
    // name someone else's table would be a licence to count, and later delete,
    // data this domain has no claim on.
    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(RetentionPolicyException::class);
});

test('a retention entry naming a column the table does not have is refused', function (): void {
    $f = retentionTenant('Retention Bad Column Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'not_a_column']]);

    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(RetentionPolicyException::class);
});

test('the report deletes nothing', function (): void {
    $f = retentionTenant('Retention Dry Run Tenant');
    retentionAuthz(true);
    retentionConfigure([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $before = DB::table(RETENTION_ISSUES_TABLE)->count();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert into|update|delete from)\s/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    // The whole point of this lane is that it reports and stops. Deletion is a
    // later, separately approved step.
    expect($writes)->toBe([])
        ->and(DB::table(RETENTION_ISSUES_TABLE)->count())->toBe($before);
});
