<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\RetentionPurgeAudit;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use App\Domains\PeopleConnector\Connector\Services\RetentionPurger;
use Illuminate\Database\Eloquent\Model;
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

/**
 * A complete policy: every connector-owned table declared indefinite, with the
 * caller's entries applied over the top.
 *
 * Retention now refuses an incomplete policy, so a test that wants to say
 * something about one table still has to have decided about the rest. Building
 * the base from the owned set rather than listing it keeps these tests honest
 * when a new model arrives.
 */
function retentionConfigureComplete(array $overrides): void
{
    $base = [];

    foreach (DomainModels::all() as $model) {
        if (is_subclass_of($model, Model::class)) {
            $base[(new $model)->getTable()] = ['days' => null];
        }
    }

    retentionConfigure([...$base, ...$overrides]);
}

test('the report counts only the rows past retention for a configured table', function (): void {
    $f = retentionTenant('Retention Counting Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->tenantId)->toBe($f['tenantId'])
        ->and($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(1)
        ->and($report->totalExpired())->toBe(1);
});

test('a table configured as indefinite is reported as retained forever rather than counted', function (): void {
    $f = retentionTenant('Retention Indefinite Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => null, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->isIndefinite(RETENTION_ISSUES_TABLE))->toBeTrue()
        ->and($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(0)
        ->and($report->totalExpired())->toBe(0);
});

test('a retention window wide enough to cover every row counts nothing', function (): void {
    $f = retentionTenant('Retention Wide Window Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 36_500, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    expect($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(0);
});

test('the report counts only the current tenant rows', function (): void {
    $mine = retentionTenant('Retention Mine Tenant');
    retentionTenant('Retention Theirs Tenant');
    app(TenantContext::class)->set($mine['tenantId']);
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    $report = app(RetentionPolicy::class)->review($mine['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    // Both tenants have exactly one row past retention. A report that counted
    // two would be telling this operator about somebody else's data.
    expect($report->expiredFor(RETENTION_ISSUES_TABLE))->toBe(1);
});

test('a caller without the retention capability is refused', function (): void {
    $f = retentionTenant('Retention Denied Tenant');
    retentionAuthz(false);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(ProviderAuthorizationException::class);
});

test('an actor from outside the current tenant is refused', function (): void {
    $f = retentionTenant('Retention Foreign Actor Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
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

test('a connector-owned table omitted from the retention policy is refused', function (): void {
    $f = retentionTenant('Retention Missing Table Tenant');
    retentionAuthz(true);
    retentionConfigure([]);

    // Missing and indefinite are different policy states. Silently omitting a
    // newly owned table leaves operators unable to tell whether its retention
    // was deliberately decided or simply never considered.
    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(RetentionPolicyException::class);
});

test('a retention entry naming a column the table does not have is refused', function (): void {
    $f = retentionTenant('Retention Bad Column Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'not_a_column']]);

    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00')))
        ->toThrow(RetentionPolicyException::class);
});

test('the report deletes nothing', function (): void {
    $f = retentionTenant('Retention Dry Run Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
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

test('the command reports the count and states that nothing was deleted', function (): void {
    $f = retentionTenant('Retention Command Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $operator = User::factory()->create(['company_id' => $f['actor']->companyId]);
    $before = DB::table(RETENTION_ISSUES_TABLE)->count();

    $this->artisan('people-connector:retention-report', ['--tenant' => $f['tenantId'], '--as' => $operator->id])
        ->expectsOutputToContain('Rows past retention: 1. Nothing was deleted.')
        ->assertExitCode(0);

    expect(DB::table(RETENTION_ISSUES_TABLE)->count())->toBe($before);
});

test('the command refuses to run without a named operator', function (): void {
    $f = retentionTenant('Retention Anonymous Command Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);

    // Retention is capability-gated. A console run that invented its own actor
    // would answer "may this person see this" with "a command asked, so yes".
    $this->artisan('people-connector:retention-report', ['--tenant' => $f['tenantId']])
        ->expectsOutputToContain('runs as a named operator')
        ->assertExitCode(1);
});

test('the command surfaces a capability refusal instead of printing a report', function (): void {
    $f = retentionTenant('Retention Denied Command Tenant');
    retentionAuthz(false);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $operator = User::factory()->create(['company_id' => $f['actor']->companyId]);

    $this->artisan('people-connector:retention-report', ['--tenant' => $f['tenantId'], '--as' => $operator->id])
        ->assertExitCode(1);
});

test('the shipped retention policy already covers every connector-owned table', function (): void {
    $f = retentionTenant('Retention Shipped Defaults Tenant');
    retentionAuthz(true);

    // Deliberately does not touch config: this asserts the policy the connector
    // actually ships with. It is the test that would have caught the three
    // tables I left undeclared, and it is what stops the next model arriving
    // without anyone deciding how long its rows are kept.
    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T12:00:00+00:00'));

    $owned = array_values(array_unique(array_map(
        static fn (string $model): string => (new $model)->getTable(),
        array_filter(DomainModels::all(), static fn (string $model): bool => is_subclass_of($model, Model::class)),
    )));

    expect(array_diff($owned, array_keys($report->tables)))->toBe([]);
});

test('the purge deletes exactly the reported tenant rows and audits every policy table', function (): void {
    $mine = retentionTenant('Retention Purge Tenant');
    retentionTenant('Retention Purge Other Tenant');
    app(TenantContext::class)->set($mine['tenantId']);
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $reviewedAt = new DateTimeImmutable('2026-09-06T12:00:00+00:00');
    $report = app(RetentionPolicy::class)->review($mine['actor'], $reviewedAt);

    $purge = app(RetentionPurger::class)->purge($mine['actor'], $report, $reviewedAt);

    expect($purge->deletedFor(RETENTION_ISSUES_TABLE))->toBe(1)
        ->and(DB::table(RETENTION_ISSUES_TABLE)->where('tenant_id', $mine['tenantId'])->pluck('external_id')->all())
        ->toBe(['NEW'])
        ->and(DB::table(RETENTION_ISSUES_TABLE)->where('tenant_id', '!=', $mine['tenantId'])->count())->toBe(2)
        ->and(RetentionPurgeAudit::query()->forTenant($mine['tenantId'])->where('run_id', $purge->runId)->count())
        ->toBe(count($report->tables));

    $audit = RetentionPurgeAudit::query()
        ->forTenant($mine['tenantId'])
        ->where('run_id', $purge->runId)
        ->where('table_name', RETENTION_ISSUES_TABLE)
        ->sole();

    expect($audit->operator_user_id)->toBe($mine['actor']->id)
        ->and($audit->expected_count)->toBe(1)
        ->and($audit->deleted_count)->toBe(1)
        ->and($audit->report_reviewed_at->equalTo($reviewedAt))->toBeTrue();
});

test('the purge refuses the whole run when live counts differ from the report', function (): void {
    $f = retentionTenant('Retention Drift Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $reviewedAt = new DateTimeImmutable('2026-09-06T12:00:00+00:00');
    $report = app(RetentionPolicy::class)->review($f['actor'], $reviewedAt);

    app(ReconciliationIssueStore::class)->report(
        $f['connectionId'],
        'sync:employee:DRIFT',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'DRIFT',
        seenAt: new DateTimeImmutable('2020-02-01T00:00:00+00:00'),
    );

    expect(fn () => app(RetentionPurger::class)->purge($f['actor'], $report, $reviewedAt))
        ->toThrow(RetentionPolicyException::class, 're-run the retention report');

    expect(DB::table(RETENTION_ISSUES_TABLE)->where('tenant_id', $f['tenantId'])->count())->toBe(3)
        ->and(RetentionPurgeAudit::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('the purge requires its separate operator capability', function (): void {
    $f = retentionTenant('Retention Purge Denied Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $reviewedAt = new DateTimeImmutable('2026-09-06T12:00:00+00:00');
    $report = app(RetentionPolicy::class)->review($f['actor'], $reviewedAt);
    retentionAuthz(false);

    expect(fn () => app(RetentionPurger::class)->purge($f['actor'], $report, $reviewedAt))
        ->toThrow(ProviderAuthorizationException::class);

    expect(DB::table(RETENTION_ISSUES_TABLE)->where('tenant_id', $f['tenantId'])->count())->toBe(2)
        ->and(RetentionPurgeAudit::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('the purge command shows the report before executing it as the named operator', function (): void {
    $f = retentionTenant('Retention Purge Command Tenant');
    retentionAuthz(true);
    retentionConfigureComplete([RETENTION_ISSUES_TABLE => ['days' => 365, 'column' => 'first_seen_at']]);
    $operator = User::factory()->create(['company_id' => $f['actor']->companyId]);

    $this->artisan('people-connector:retention-purge', [
        '--tenant' => $f['tenantId'],
        '--as' => $operator->id,
        '--yes' => true,
    ])
        ->expectsOutputToContain('Rows eligible for purge: 1.')
        ->expectsOutputToContain('deleted 1 rows')
        ->assertExitCode(0);

    expect(DB::table(RETENTION_ISSUES_TABLE)->where('tenant_id', $f['tenantId'])->count())->toBe(1);
});
