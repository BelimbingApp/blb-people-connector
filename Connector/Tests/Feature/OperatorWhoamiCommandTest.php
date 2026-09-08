<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * connector:operator:whoami (#235): each connector capability as the
 * authorization service decides it for the named operator, inside their
 * own tenant only. Self-contained: helpers are prefixed whoami.
 */
afterEach(fn () => app(TenantContext::class)->clear());

/** An authorization service that allows exactly the named capabilities. */
function whoamiAuthz(array $allowed): void
{
    app()->instance(AuthorizationService::class, new class($allowed) implements AuthorizationService
    {
        public function __construct(private array $allowed) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return in_array($capability, $this->allowed, true)
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

/** @return array{tenantId: int, companyId: int, operator: User} */
function whoamiTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return ['tenantId' => (int) $tenant->id, 'companyId' => (int) $company->id, 'operator' => User::factory()->create(['company_id' => $company->id])];
}

test('an operator holding export and not import sees one allowed and one denied row, with tenant and company', function (): void {
    whoamiAuthz(['people-connector.identity.export']);
    $t = whoamiTenant('Whoami Tenant');

    expect(Artisan::call('connector:operator:whoami', ['--tenant' => $t['tenantId'], '--as' => $t['operator']->id, '--json' => true]))->toBe(0);
    $identity = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    $rows = collect($identity['capabilities'])->keyBy('capability');
    expect($identity['user'])->toBe((int) $t['operator']->id)
        ->and($identity['tenant'])->toBe($t['tenantId'])
        ->and($identity['company'])->toBe($t['companyId'])
        ->and($rows['people-connector.identity.export'])->toBe(['capability' => 'people-connector.identity.export', 'allowed' => true, 'reason' => AuthorizationReasonCode::ALLOWED->value])
        ->and($rows['people-connector.identity.import']['allowed'])->toBeFalse()
        ->and($rows['people-connector.identity.import']['reason'])->toBe(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY->value)
        ->and($rows->where('allowed', true)->count())->toBe(1)
        ->and($rows->keys()->all())->toContain('people-connector.connection.list', 'people-connector.provider.employee-directory.read')
        ->and($rows->keys()->all())->toBe($rows->keys()->sort()->values()->all())
        ->and($rows->keys()->every(fn (string $key): bool => str_starts_with($key, 'people-connector.')))->toBeTrue();

    expect(Artisan::call('connector:operator:whoami', ['--tenant' => $t['tenantId'], '--as' => $t['operator']->id]))->toBe(0)
        ->and(Artisan::output())->toContain("Operator {$t['operator']->id} (user) in tenant {$t['tenantId']}, company {$t['companyId']}.", 'people-connector.identity.export', 'allowed', 'people-connector.identity.import', 'denied');
});

test('an operator of another tenant is refused, and --as is required', function (): void {
    whoamiAuthz(['people-connector.identity.export']);
    $t = whoamiTenant('Whoami Tenant');
    $other = whoamiTenant('Other Whoami Tenant');

    expect(Artisan::call('connector:operator:whoami', ['--tenant' => $t['tenantId'], '--as' => $other['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain("operator's own tenant")
        ->and(Artisan::output())->not->toContain('allowed');
    expect(Artisan::call('connector:operator:whoami', ['--tenant' => $t['tenantId']]))->toBe(1)
        ->and(Artisan::output())->toContain('pass --as=<user id>');
    expect(Artisan::call('connector:operator:whoami', ['--tenant' => $t['tenantId'], '--as' => 999999]))->toBe(1)
        ->and(Artisan::output())->toContain('No user [999999]');
});
