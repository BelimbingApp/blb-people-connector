<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;
use App\Domains\PeopleConnector\Connector\Models\PrivilegedSupportAction;
use App\Domains\PeopleConnector\Connector\Models\PrivilegedSupportGrant;
use App\Domains\PeopleConnector\Connector\Services\PrivilegedSupportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function grantImmutabilityActor(int $tenantId, int $companyId, int $id): Actor
{
    return new Actor(PrincipalType::USER, $id, $companyId, tenantId: $tenantId);
}

/**
 * @return array{tenantId: int, companyId: int, grant: PrivilegedSupportGrant, service: PrivilegedSupportService}
 */
function grantImmutabilityFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldReceive('authorize')->andReturnNull();
    app()->instance(AuthorizationService::class, $authorization);

    $issuedAt = new DateTimeImmutable;
    $service = app(PrivilegedSupportService::class);
    $grant = $service->issue(
        grantImmutabilityActor((int) $tenant->id, (int) $company->id, 70),
        grantImmutabilityActor((int) $tenant->id, (int) $company->id, 71),
        ProviderScope::company((int) $company->id),
        ['employee_directory:read'],
        'Prove grant immutability',
        $issuedAt,
        $issuedAt->modify('+15 minutes'),
    );

    return [
        'tenantId' => (int) $tenant->id,
        'companyId' => (int) $company->id,
        'grant' => $grant,
        'service' => $service,
    ];
}

afterEach(fn () => app(TenantContext::class)->clear());

test('updating a privileged support grant purpose is refused', function (): void {
    $f = grantImmutabilityFixture('Grant Update Refuse Tenant');
    $before = $f['grant']->purpose;

    $refusal = null;
    try {
        DB::transaction(fn () => $f['grant']->update(['purpose' => 'tampered']));
    } catch (AppendOnlyRecordException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(AppendOnlyRecordException::class)
        ->and($f['grant']->refresh()->purpose)->toBe($before);
});

test('deleting a privileged support grant is refused', function (): void {
    $f = grantImmutabilityFixture('Grant Delete Refuse Tenant');
    $id = $f['grant']->id;

    $refusal = null;
    try {
        DB::transaction(fn () => $f['grant']->delete());
    } catch (AppendOnlyRecordException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(AppendOnlyRecordException::class)
        ->and(PrivilegedSupportGrant::query()->find($id))->not->toBeNull();
});

test('revoke still succeeds and appends the grant_revoked action', function (): void {
    $f = grantImmutabilityFixture('Grant Revoke Still Works Tenant');
    $actor = grantImmutabilityActor($f['tenantId'], $f['companyId'], 70);
    $beforeActions = PrivilegedSupportAction::query()->where('grant_id', $f['grant']->id)->count();

    $f['service']->revoke($f['grant'], $actor);

    $grant = $f['grant']->refresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and(PrivilegedSupportAction::query()
            ->where('grant_id', $grant->id)
            ->where('action', 'grant_revoked')
            ->count())->toBe(1)
        ->and(PrivilegedSupportAction::query()->where('grant_id', $grant->id)->count())
        ->toBe($beforeActions + 1);
});

test('the revoke carve-out refuses an id change on a still-active grant', function (): void {
    // desktop-luna's [P1] on #332. The carve-out compared every column except
    // the primary key, so an update could rewrite the grant identity as long as
    // it also set revoked_at. Asserting that after a revoke proves nothing: the
    // OLD.revoked_at IS NULL arm has already failed, so the trigger refuses
    // whatever id is passed. The grant here must still be active, and the
    // assertion below says so out loud rather than relying on statement order --
    // that is what stopped the original case from being a real control.
    $f = grantImmutabilityFixture('Grant Trigger Identity Tenant');
    $table = 'people_connector_connector_privileged_support_grants';
    $id = (int) $f['grant']->id;

    expect(DB::table($table)->where('id', $id)->value('revoked_at'))->toBeNull();

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update([
        'revoked_at' => now(),
        'id' => $id + 1000,
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'append-only');

    expect(DB::table($table)->where('id', $id)->exists())->toBeTrue();
    expect(DB::table($table)->where('id', $id + 1000)->exists())->toBeFalse();
});

test('raw query-builder writes hit the DB immutability trigger, not only the model guard', function (): void {
    $f = grantImmutabilityFixture('Grant Trigger Layer Tenant');
    $table = 'people_connector_connector_privileged_support_grants';
    $id = (int) $f['grant']->id;
    $purpose = $f['grant']->purpose;

    // Each attempt needs its own transaction: on PostgreSQL a raised exception
    // poisons the surrounding one (25P02) and the next refusal would misreport.
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['purpose' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update([
        'revoked_at' => now(),
        'updated_at' => now(),
    ])))->not->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update([
        'revoked_at' => now(),
        'id' => $id + 1,
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'append-only');

    // Fresh grant: revoke carve-out refuses when purpose rides along.
    $fresh = grantImmutabilityFixture('Grant Trigger Purpose Ride Tenant');
    $freshId = (int) $fresh['grant']->id;
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $freshId)->update([
        'revoked_at' => now(),
        'purpose' => 'also tampered',
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'append-only');

    expect(DB::table($table)->where('id', $id)->value('purpose'))->toBe($purpose)
        ->and(DB::table($table)->where('id', $id)->value('revoked_at'))->not->toBeNull()
        ->and(DB::table($table)->where('id', $freshId)->value('purpose'))->toBe($fresh['grant']->purpose)
        ->and(DB::table($table)->where('id', $freshId)->value('revoked_at'))->toBeNull();
});
