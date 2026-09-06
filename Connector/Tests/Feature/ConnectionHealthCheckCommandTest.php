<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * connector:health:check (#209): every active connection's adapter is
 * pinged and its declared capabilities are compared with the evidence
 * register; drift exits non-zero. Self-contained: helpers are prefixed
 * healthCheck.
 */
beforeEach(function (): void {
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
    if (app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    $path = config('people-connector.capability_register');
    config()->set('people-connector.capability_register', null);
    if (is_string($path) && str_starts_with($path, sys_get_temp_dir()) && is_file($path)) {
        unlink($path);
        @unlink(substr($path, 0, -5));
    }
});

/** @param array<string, list<string>> $verified */
function healthCheckRegister(array $verified): string
{
    $path = tempnam(sys_get_temp_dir(), 'capability-register-').'.json';
    file_put_contents($path, json_encode(['providers' => array_map(fn (array $list): array => ['verified' => $list], $verified)]));
    config()->set('people-connector.capability_register', $path);

    return $path;
}

/** @return array{tenantId: int, operator: User, connection: int} */
function healthCheckTenant(string $name, string $provider = FirstPartyPeopleAdapter::ID): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $provider)->id);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => (int) $connection->id];
}

function healthCheckRun(array $tenant, array $extra = []): int
{
    return Artisan::call('connector:health:check', ['--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, ...$extra]);
}

test('an adapter whose declarations match the register is healthy and exits zero', function (): void {
    healthCheckRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'organization_directory', 'employee_directory']]);
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['blocked'])->toBeFalse()
        ->and($report['connections'])->toHaveCount(1)
        ->and($report['connections'][0]['connection'])->toBe($t['connection'])
        ->and($report['connections'][0]['health'])->toBe('healthy')
        ->and($report['connections'][0]['declared'])->toBe(['company_directory', 'organization_directory', 'employee_directory'])
        ->and($report['connections'][0]['unsupported_declared'])->toBe([])
        ->and($report['connections'][0]['withdrawn'])->toBe([]);
});

test('a capability declared without evidence is drift and exits non-zero', function (): void {
    healthCheckRegister([FirstPartyPeopleAdapter::ID => ['company_directory']]);
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t))->toBe(1)
        ->and(Artisan::output())->toContain('organization_directory, employee_directory', 'declares unverified capabilities');
});

test('a verified capability the adapter no longer declares is reported as withdrawn without blocking', function (): void {
    healthCheckRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'organization_directory', 'employee_directory', 'payroll']]);
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['connections'][0]['withdrawn'])->toBe(['payroll']);
});

test('a provider absent from the register has verified nothing, so every declaration is drift', function (): void {
    healthCheckRegister(['hr2000.sbg' => []]);
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['connections'][0]['in_register'])->toBeFalse()
        ->and($report['connections'][0]['unsupported_declared'])->toHaveCount(3);
});

test('a connection whose adapter is not registered blocks rather than passes', function (): void {
    healthCheckRegister([]);
    $t = healthCheckTenant('Health Check Tenant', 'test.ghost');

    expect(healthCheckRun($t))->toBe(1)
        ->and(Artisan::output())->toContain('test.ghost (not registered)', 'is not registered');
});

test('the check sees only the operator tenant and refuses an operator outside it', function (): void {
    healthCheckRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'organization_directory', 'employee_directory']]);
    $t = healthCheckTenant('Health Check Tenant');
    $other = healthCheckTenant('Other Health Tenant');

    expect(healthCheckRun($t, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['connections'], 'connection'))->toBe([$t['connection']]);

    expect(Artisan::call('connector:health:check', ['--tenant' => $t['tenantId'], '--as' => $other['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('requires an operator inside it')
        ->and(Artisan::call('connector:health:check', ['--tenant' => $t['tenantId']]))->toBe(1)
        ->and(Artisan::output())->toContain('pass --as=<user id>');
});

test('a register naming an unknown capability is refused, not ignored', function (): void {
    healthCheckRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'telepathy']]);
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t))->toBe(1)
        ->and(Artisan::output())->toContain('unknown capability');
});

test('the shipped register verifies exactly what the first-party adapter declares and nothing for HR2000', function (): void {
    $t = healthCheckTenant('Health Check Tenant');

    expect(healthCheckRun($t, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($report['register'])->toEndWith('docs/providers/capability-register.json')
        ->and($report['connections'][0]['unsupported_declared'])->toBe([])
        ->and($report['connections'][0]['withdrawn'])->toBe([])
        ->and(json_decode(file_get_contents($report['register']), true)['providers']['hr2000.sbg']['verified'])->toBe([]);
});
