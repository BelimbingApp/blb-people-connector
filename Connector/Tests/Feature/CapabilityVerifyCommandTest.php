<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * connector:capability:verify (#231): one appended register entry with its
 * evidence, one audit row, and the health check stops reporting drift.
 * Self-contained: helpers are prefixed capVerify.
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
function capVerifyRegister(array $verified): string
{
    $path = tempnam(sys_get_temp_dir(), 'capability-register-').'.json';
    file_put_contents($path, json_encode(['description' => 'test register', 'providers' => array_map(fn (array $list): array => ['verified' => $list], $verified)], JSON_PRETTY_PRINT));
    config()->set('people-connector.capability_register', $path);

    return $path;
}

/** @return array{tenantId: int, operator: User, connection: int} */
function capVerifyTenant(string $name, string $provider = FirstPartyPeopleAdapter::ID): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), $provider)->id);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => (int) $connection->id];
}

function capVerifyRun(array $tenant, string $provider, string $capability, ?string $evidence = 'https://tracker.example/EVIDENCE-1', array $extra = []): int
{
    $args = ['provider' => $provider, 'capability' => $capability, '--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, ...$extra];
    if ($evidence !== null) {
        $args['--evidence'] = $evidence;
    }

    return Artisan::call('connector:capability:verify', $args);
}

function capVerifyHealthDrift(array $tenant): array
{
    Artisan::call('connector:health:check', ['--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, '--json' => true]);

    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['connections'][0];
}

test('verifying a declared capability appends one register entry with the evidence and the health check stops reporting drift for it', function (): void {
    $path = capVerifyRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'organization_directory']]);
    $t = capVerifyTenant('Verify Tenant');
    expect(capVerifyHealthDrift($t)['unsupported_declared'])->toBe(['employee_directory']);
    $before = json_decode(file_get_contents($path), true);

    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'employee_directory'))->toBe(0)
        ->and(Artisan::output())->toContain('Recorded evidence for [employee_directory]')
        ->and(Artisan::output())->not->toContain('does not declare');

    $after = json_decode(file_get_contents($path), true);
    $entries = $after['providers'][FirstPartyPeopleAdapter::ID]['verified'];
    expect($entries)->toHaveCount(3)
        ->and(array_slice($entries, 0, 2))->toBe($before['providers'][FirstPartyPeopleAdapter::ID]['verified'])
        ->and($entries[2]['capability'])->toBe('employee_directory')
        ->and($entries[2]['evidence'])->toBe('https://tracker.example/EVIDENCE-1')
        ->and($entries[2]['verified_by'])->toBe('user:'.$t['operator']->id)
        ->and($entries[2]['verified_at'])->toBeString()
        ->and($after['description'])->toBe('test register');

    $row = capVerifyHealthDrift($t);
    expect($row['unsupported_declared'])->toBe([])
        ->and($row['withdrawn'])->toBe([]);

    $audit = OperatorAudit::query()->forTenant($t['tenantId'])->sole();
    expect($audit->operation)->toBe(OperatorAuditOperation::CapabilityVerified)
        ->and($audit->actor_id)->toBe((int) $t['operator']->id)
        ->and($audit->connection_id)->toBe($t['connection'])
        ->and($audit->review_reference)->toBe('https://tracker.example/EVIDENCE-1')
        ->and($audit->after_summary['capability'] ?? null)->toBe('employee_directory')
        ->and($audit->after_summary['declared_by_adapter'] ?? null)->toBeTrue();
});

test('an unknown capability name is refused and nothing is written', function (): void {
    $path = capVerifyRegister([FirstPartyPeopleAdapter::ID => ['company_directory']]);
    $t = capVerifyTenant('Verify Tenant');
    $bytes = file_get_contents($path);

    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'telepathy'))->toBe(1)
        ->and(Artisan::output())->toContain('Unknown capability [telepathy]')
        ->and(file_get_contents($path))->toBe($bytes)
        ->and(OperatorAudit::query()->count())->toBe(0);
});

test('re-verifying an already verified capability is a no-op with a message', function (): void {
    $path = capVerifyRegister([FirstPartyPeopleAdapter::ID => ['company_directory']]);
    $t = capVerifyTenant('Verify Tenant');
    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'employee_directory', 'https://tracker.example/FIRST'))->toBe(0);
    $bytes = file_get_contents($path);

    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'employee_directory', 'https://tracker.example/SECOND'))->toBe(0)
        ->and(Artisan::output())->toContain('already verified', 'https://tracker.example/FIRST', 'nothing written')
        ->and(file_get_contents($path))->toBe($bytes)
        ->and(OperatorAudit::query()->count())->toBe(1);

    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'company_directory'))->toBe(0)
        ->and(Artisan::output())->toContain('already verified')
        ->and(file_get_contents($path))->toBe($bytes);
});

test('a provider without a connection in the tenant, missing evidence, and an operator outside the tenant are refused', function (): void {
    $path = capVerifyRegister([]);
    $t = capVerifyTenant('Verify Tenant');
    $other = capVerifyTenant('Other Verify Tenant');
    $bytes = file_get_contents($path);

    expect(capVerifyRun($t, 'hr2000.sbg', 'employee_directory'))->toBe(1)
        ->and(Artisan::output())->toContain('not configured in the current tenant');
    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'employee_directory', '  '))->toBe(1)
        ->and(Artisan::output())->toContain('Evidence is required');
    expect(Artisan::call('connector:capability:verify', ['provider' => FirstPartyPeopleAdapter::ID, 'capability' => 'employee_directory', '--evidence' => 'x', '--tenant' => $t['tenantId'], '--as' => $other['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('operator inside the current tenant');
    expect(Artisan::call('connector:capability:verify', ['provider' => FirstPartyPeopleAdapter::ID, 'capability' => 'employee_directory', '--evidence' => 'x', '--tenant' => $t['tenantId']]))->toBe(1)
        ->and(Artisan::output())->toContain('pass --as=<user id>');

    expect(file_get_contents($path))->toBe($bytes)
        ->and(OperatorAudit::query()->count())->toBe(0);
});

test('evidence for a capability the adapter does not declare is recorded with a warning and shows as withdrawn', function (): void {
    capVerifyRegister([FirstPartyPeopleAdapter::ID => ['company_directory', 'organization_directory', 'employee_directory']]);
    $t = capVerifyTenant('Verify Tenant');

    expect(capVerifyRun($t, FirstPartyPeopleAdapter::ID, 'payroll'))->toBe(0)
        ->and(Artisan::output())->toContain('Recorded evidence for [payroll]', 'does not declare [payroll] yet');

    expect(capVerifyHealthDrift($t)['withdrawn'])->toBe(['payroll'])
        ->and(OperatorAudit::query()->forTenant($t['tenantId'])->sole()->after_summary['declared_by_adapter'] ?? null)->toBeFalse();
});

test('a register with object entries still loads for the health check and keeps bare names valid', function (): void {
    $path = capVerifyRegister([]);
    file_put_contents($path, json_encode(['providers' => [FirstPartyPeopleAdapter::ID => ['verified' => [
        'company_directory',
        ['capability' => 'organization_directory', 'evidence' => 'ref-1', 'verified_at' => '2026-09-07T00:00:00+00:00', 'verified_by' => 'user:1'],
        ['capability' => 'employee_directory'],
    ]]]]));
    $t = capVerifyTenant('Verify Tenant');

    $row = capVerifyHealthDrift($t);
    expect($row['unsupported_declared'])->toBe([])
        ->and($row['withdrawn'])->toBe([]);
});
