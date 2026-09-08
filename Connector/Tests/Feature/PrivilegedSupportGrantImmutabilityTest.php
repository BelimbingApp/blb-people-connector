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
