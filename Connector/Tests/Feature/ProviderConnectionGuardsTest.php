<?php

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The four save guards in ProviderConnection::booted().
 *
 * Helpers below are local to this file on purpose: Pest function names are
 * global across the suite, and this file has to stand up when it runs alone.
 */
function providerConnectionGuardsTable(): string
{
    return 'people_connector_connector_provider_connections';
}

function providerConnectionGuardsRows(): int
{
    return (int) DB::table(providerConnectionGuardsTable())->count();
}

/**
 * @return array{0: int, 1: int} tenant id and a company inside it
 */
function providerConnectionGuardsTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name], ['name' => $name.' Company']);

    return [(int) $tenant->id, (int) $company->id];
}

function providerConnectionGuardsMake(int $tenantId, ?int $companyId = null, array $overrides = []): ProviderConnection
{
    $connection = new ProviderConnection(array_merge([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'scope_key' => $companyId === null ? 'tenant' : 'company:'.$companyId,
        'provider_id' => 'guards.people',
        'status' => ProviderConnection::STATUS_INACTIVE,
    ], $overrides));

    $connection->save();

    return $connection;
}

function providerConnectionGuardsFresh(int $id): ProviderConnection
{
    return ProviderConnection::query()->whereKey($id)->firstOrFail();
}

test('a provider connection refuses a scope key that disagrees with its company axis', function (): void {
    [$tenantId, $companyId] = providerConnectionGuardsTenant('Scope Guard Tenant');
    $before = providerConnectionGuardsRows();

    expect(fn () => providerConnectionGuardsMake($tenantId, $companyId, ['scope_key' => 'tenant']))
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection scope fields are inconsistent.');

    expect(fn () => providerConnectionGuardsMake($tenantId, null, ['scope_key' => 'company:'.$companyId]))
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection scope fields are inconsistent.');

    // A company scope naming a different company is the same refusal: the key
    // is derived from company_id, never merely checked for shape.
    expect(fn () => providerConnectionGuardsMake($tenantId, $companyId, ['scope_key' => 'company:'.($companyId + 1000)]))
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection scope fields are inconsistent.');

    expect(providerConnectionGuardsRows())->toBe($before);
});

test('a provider connection refuses a status outside active inactive and retired', function (): void {
    [$tenantId] = providerConnectionGuardsTenant('Status Guard Tenant');
    $before = providerConnectionGuardsRows();

    expect(fn () => providerConnectionGuardsMake($tenantId, null, ['status' => 'paused']))
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection status is invalid.');

    expect(providerConnectionGuardsRows())->toBe($before);

    $connection = providerConnectionGuardsMake($tenantId);
    $connection->status = 'paused';

    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection status is invalid.')
        ->and(providerConnectionGuardsFresh((int) $connection->id)->status)->toBe(ProviderConnection::STATUS_INACTIVE);
});

test('active scope key is derived from status on every save and never accepted from the caller', function (): void {
    [$tenantId, $companyId] = providerConnectionGuardsTenant('Derivation Tenant');
    $connection = providerConnectionGuardsMake($tenantId, $companyId);
    $id = (int) $connection->id;

    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBeNull();

    $connection->status = ProviderConnection::STATUS_ACTIVE;
    $connection->save();
    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBe('company:'.$companyId);

    $connection->status = ProviderConnection::STATUS_INACTIVE;
    $connection->save();
    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBeNull();

    $connection->status = ProviderConnection::STATUS_ACTIVE;
    $connection->save();
    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBe('company:'.$companyId);

    $connection->status = ProviderConnection::STATUS_RETIRED;
    $connection->save();
    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBeNull();

    // A caller writing the column by hand does not get to keep it: the value is
    // recomputed from status inside the same save.
    $connection->active_scope_key = 'company:'.$companyId;
    $connection->save();
    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBeNull();

    $inactive = providerConnectionGuardsMake($tenantId, null, ['active_scope_key' => 'tenant']);
    expect(providerConnectionGuardsFresh((int) $inactive->id)->active_scope_key)->toBeNull();
});

test('a persisted provider connection refuses edits to its tenant provider company and scope', function (): void {
    [$tenantId, $companyId] = providerConnectionGuardsTenant('Immutable Tenant');
    [$otherTenantId] = providerConnectionGuardsTenant('Immutable Other Tenant');
    $connection = providerConnectionGuardsMake($tenantId);
    $id = (int) $connection->id;

    $connection->tenant_id = $otherTenantId;
    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection identity and scope are immutable.')
        ->and((int) providerConnectionGuardsFresh($id)->tenant_id)->toBe($tenantId);

    $connection = providerConnectionGuardsFresh($id);
    $connection->provider_id = 'guards.replacement';
    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection identity and scope are immutable.')
        ->and(providerConnectionGuardsFresh($id)->provider_id)->toBe('guards.people');

    // company_id and scope_key have to move together to reach this guard at
    // all: either one alone is stopped earlier by the consistency check, which
    // the two cases after this one measure.
    $connection = providerConnectionGuardsFresh($id);
    $connection->company_id = $companyId;
    $connection->scope_key = 'company:'.$companyId;
    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection identity and scope are immutable.');

    $reread = providerConnectionGuardsFresh($id);
    expect($reread->company_id)->toBeNull()
        ->and($reread->scope_key)->toBe('tenant');

    $connection = providerConnectionGuardsFresh($id);
    $connection->company_id = $companyId;
    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection scope fields are inconsistent.')
        ->and(providerConnectionGuardsFresh($id)->company_id)->toBeNull();

    $connection = providerConnectionGuardsFresh($id);
    $connection->scope_key = 'company:'.$companyId;
    expect(fn () => $connection->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection scope fields are inconsistent.')
        ->and(providerConnectionGuardsFresh($id)->scope_key)->toBe('tenant');
});

test('a query builder update cannot leave a retired connection holding an active scope key', function (): void {
    [$tenantId] = providerConnectionGuardsTenant('Bypass Tenant');
    $connection = providerConnectionGuardsMake($tenantId, null, ['status' => ProviderConnection::STATUS_ACTIVE]);
    $id = (int) $connection->id;

    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBe('tenant');

    // Its own transaction: on Postgres a refused statement poisons the
    // surrounding one, and the Pest harness wraps every test in exactly that.
    $bypass = null;

    try {
        DB::transaction(function () use ($id): void {
            ProviderConnection::query()->whereKey($id)->update(['status' => ProviderConnection::STATUS_RETIRED]);
        });
    } catch (Throwable $e) {
        $bypass = $e;
    }

    $row = DB::table(providerConnectionGuardsTable())->where('id', $id)->first();

    expect($bypass)->toBeInstanceOf(QueryException::class);
    expect($bypass->getMessage())->toContain('active scope key must be derived from its status');
    expect($row->status)->toBe(ProviderConnection::STATUS_ACTIVE);
    expect($row->active_scope_key)->toBe('tenant');

    // Retiring through the model, or nulling the derived column in the same
    // statement, stays allowed.
    DB::transaction(function () use ($id): void {
        ProviderConnection::query()->whereKey($id)->update([
            'status' => ProviderConnection::STATUS_RETIRED,
            'active_scope_key' => null,
        ]);
    });

    expect(providerConnectionGuardsFresh($id)->active_scope_key)->toBeNull();
    expect(providerConnectionGuardsFresh($id)->status)->toBe(ProviderConnection::STATUS_RETIRED);
});

test('two tenants hold the same scope key at once and neither connection can take the other tenant id', function (): void {
    // Scope keys are unique per tenant, not globally: the unique indexes are
    // (tenant_id, scope_key, provider_id) and (tenant_id, active_scope_key).
    // Company ids are globally unique, so the tenant scope key is the one that
    // can actually be made to collide across tenants.
    [$tenantA] = providerConnectionGuardsTenant('Coexist Tenant A');
    [$tenantB] = providerConnectionGuardsTenant('Coexist Tenant B');

    $a = providerConnectionGuardsMake($tenantA, null, ['status' => ProviderConnection::STATUS_ACTIVE]);
    $b = providerConnectionGuardsMake($tenantB, null, ['status' => ProviderConnection::STATUS_ACTIVE]);
    $idA = (int) $a->id;
    $idB = (int) $b->id;

    expect(providerConnectionGuardsFresh($idA)->active_scope_key)->toBe('tenant');
    expect(providerConnectionGuardsFresh($idB)->active_scope_key)->toBe('tenant');
    expect(providerConnectionGuardsFresh($idA)->scope_key)->toBe(providerConnectionGuardsFresh($idB)->scope_key);

    $a->tenant_id = $tenantB;
    expect(fn () => $a->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection identity and scope are immutable.');

    $b->tenant_id = $tenantA;
    expect(fn () => $b->save())
        ->toThrow(InvalidProviderConfigurationException::class, 'Provider connection identity and scope are immutable.');

    expect((int) providerConnectionGuardsFresh($idA)->tenant_id)->toBe($tenantA);
    expect((int) providerConnectionGuardsFresh($idB)->tenant_id)->toBe($tenantB);
});
