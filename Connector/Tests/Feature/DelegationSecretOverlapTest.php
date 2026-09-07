<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Services\SupportBundleRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The operator side of delegation secret rotation (#262).
 *
 * Self-contained: every helper here is prefixed delegationOverlap, because
 * Pest helper names are global across the suite.
 *
 * The whole row is a comparison between now and a configured instant, so the
 * clock is frozen for every case and each boundary is asserted from both
 * sides of the same instant.
 */

beforeEach(function (): void {
    config()->set('queue.default', 'database');
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
    Carbon::setTestNow('2026-09-07T12:00:00+00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @return array{0: int, 1: int, 2: User} */
function delegationOverlapTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return [(int) $tenant->id, (int) $company->id, User::factory()->create(['company_id' => $company->id])];
}

/** @return array{0: int, 1: Collection<int, array<string, mixed>>, 2: string} exit code, rows, raw output */
function delegationOverlapDoctor(int $tenantId, User $operator): array
{
    $exit = Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--json' => true]);
    $output = trim(Artisan::output());

    return [$exit, collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks']), $output];
}

function delegationOverlapRow(string $status, int $count, string $detail): array
{
    return ['check' => 'delegation_secret_overlap', 'status' => $status, 'count' => $count, 'detail' => $detail];
}

test('the doctor reports the delegation secret overlap green, then yellow inside the window, then red at its expiry instant and after', function (): void {
    [$tenantId, , $operator] = delegationOverlapTenant('Delegation Overlap Tenant');
    config()->set('people-connector.delegation.secret', str_repeat('b', 64));

    [$exit, $rows] = delegationOverlapDoctor($tenantId, $operator);
    expect($exit)->toBe(0)
        ->and($rows->firstWhere('check', 'delegation_secret_overlap'))
        ->toBe(delegationOverlapRow('green', 0, 'no previous delegation secret'));

    config()->set('people-connector.delegation.previous_secret', str_repeat('a', 64));
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    // Frozen at 12:00: a rotation in progress. Yellow is advisory and does not
    // fail the doctor.
    [$exit, $rows] = delegationOverlapDoctor($tenantId, $operator);
    expect($exit)->toBe(0)
        ->and($rows->firstWhere('check', 'delegation_secret_overlap'))
        ->toBe(delegationOverlapRow('yellow', 1, 'previous delegation secret accepted until 2026-09-07T12:02:00+00:00'));

    // Both sides of the same instant: at the expiry the overlap is already
    // over, which is the moment verify() stops consulting the old key too.
    foreach (['2026-09-07T12:02:00+00:00', '2026-09-07T12:02:01+00:00'] as $lapsed) {
        Carbon::setTestNow($lapsed);
        [$exit, $rows] = delegationOverlapDoctor($tenantId, $operator);
        expect($exit)->toBe(1)
            ->and($rows->firstWhere('check', 'delegation_secret_overlap'))
            ->toBe(delegationOverlapRow('red', 1, 'previous delegation secret lapsed at 2026-09-07T12:02:00+00:00'));
    }
});

test('the doctor is red on a previous delegation secret that will never be accepted, and prints neither secret', function (): void {
    [$tenantId, , $operator] = delegationOverlapTenant('Delegation Overlap Unfinished Tenant');
    $current = str_repeat('b', 64);
    $previous = str_repeat('a', 64);
    config()->set('people-connector.delegation.secret', $current);
    config()->set('people-connector.delegation.previous_secret', $previous);

    // An old key with no readable end date is a key nobody retired: the
    // operator forgot to finish the rotation and nothing else would say so.
    foreach ([null, '', 'whenever'] as $expiry) {
        config()->set('people-connector.delegation.previous_secret_expires_at', $expiry);
        [$exit, $rows, $output] = delegationOverlapDoctor($tenantId, $operator);

        expect($exit)->toBe(1)
            ->and($rows->firstWhere('check', 'delegation_secret_overlap'))
            ->toBe(delegationOverlapRow('red', 1, 'previous delegation secret has no readable expiry'));
        // One needle per negated assertion: not->toContain(a, b) only fails
        // when both are present.
        expect($output)->not->toContain($previous);
        expect($output)->not->toContain($current);
    }

    // A key too weak to sign with is too weak to accept, so the doctor must
    // not report a window that verify() will never open.
    config()->set('people-connector.delegation.previous_secret', 'short');
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');
    [$exit, $rows] = delegationOverlapDoctor($tenantId, $operator);

    expect($exit)->toBe(1)
        ->and($rows->firstWhere('check', 'delegation_secret_overlap'))
        ->toBe(delegationOverlapRow('red', 1, 'previous delegation secret is shorter than 32 bytes and is never accepted'));

    // The other operator surface that reads this config: a support bundle
    // carries people-connector config, so the retired key has to be
    // fingerprinted there as well (#250).
    config()->set('people-connector.delegation.previous_secret', $previous);
    $bundled = json_encode((new SupportBundleRedactor)->redact(config('people-connector', [])), JSON_THROW_ON_ERROR);
    expect($bundled)->not->toContain($previous);
    expect($bundled)->not->toContain($current);
});

test('every tenant is told the same delegation overlap and none of another tenants rows', function (): void {
    [$tenantId, , $operator] = delegationOverlapTenant('Delegation Overlap First Tenant');
    [$otherTenantId, , $otherOperator] = delegationOverlapTenant('Delegation Overlap Second Tenant');
    config()->set('people-connector.delegation.secret', str_repeat('b', 64));
    config()->set('people-connector.delegation.previous_secret', str_repeat('a', 64));
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    // The signing key is deployment-wide, so this row must cross the tenant
    // line; the first tenant's stale sync is the row that must not.
    Queue::connection('database')->pushOn(RunIncrementalWorkforceSync::QUEUE, new RunIncrementalWorkforceSync($tenantId, 999));
    DB::table('jobs')->latest('id')->limit(1)->update(['created_at' => now()->subHours(2)->timestamp]);

    [$exit, $rows] = delegationOverlapDoctor($tenantId, $operator);
    [$otherExit, $otherRows] = delegationOverlapDoctor($otherTenantId, $otherOperator);

    $overlap = delegationOverlapRow('yellow', 1, 'previous delegation secret accepted until 2026-09-07T12:02:00+00:00');

    expect($exit)->toBe(1)
        ->and($rows->firstWhere('check', 'delegation_secret_overlap'))->toBe($overlap)
        ->and($rows->firstWhere('check', 'webhook_deliveries'))
        ->toBe(['check' => 'webhook_deliveries', 'status' => 'red', 'count' => 1, 'detail' => '1 stale']);

    expect($otherExit)->toBe(0)
        ->and($otherRows->firstWhere('check', 'delegation_secret_overlap'))->toBe($overlap)
        ->and($otherRows->firstWhere('check', 'webhook_deliveries'))
        ->toBe(['check' => 'webhook_deliveries', 'status' => 'green', 'count' => 0, 'detail' => '0 stale']);
});
