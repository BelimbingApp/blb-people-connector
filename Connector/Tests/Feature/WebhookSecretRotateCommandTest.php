<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * connector:webhook:secret:rotate (#247): a new secret printed once, an
 * audit row with fingerprints only, and the doctor's overlap row while a
 * previous secret is still verifying. Self-contained: helpers are prefixed
 * secretRotate.
 */
function secretRotateAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException(providerId: 'connector', operation: 'test', message: 'The actor lacks the capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

beforeEach(function (): void {
    secretRotateAuthz(true);
    if (app(ProviderRegistry::class)->find(FirstPartyPeopleAdapter::ID) === null) {
        app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    }
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    config()->set('people-connector.webhook.secrets', []);
    config()->set('queue.default', 'sync');
});

/** @return array{tenantId: int, operator: User, connection: int} */
function secretRotateTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), FirstPartyPeopleAdapter::ID)->id);

    return ['tenantId' => (int) $tenant->id, 'operator' => User::factory()->create(['company_id' => $company->id]), 'connection' => (int) $connection->id];
}

function secretRotateRun(array $tenant, int $connection, array $extra = []): int
{
    return Artisan::call('connector:webhook:secret:rotate', ['connection' => $connection, '--tenant' => $tenant['tenantId'], '--as' => $tenant['operator']->id, ...$extra]);
}

test('a rotation prints the new secret once, the entry list with the previous secret as a fingerprint placeholder, and audits fingerprints only', function (): void {
    $t = secretRotateTenant('Rotate Tenant');
    config()->set("people-connector.webhook.secrets.{$t['connection']}", 'old-secret-value');
    $this->travelTo('2026-09-07 10:00:00');

    expect(secretRotateRun($t, $t['connection'], ['--overlap-minutes' => 90]))->toBe(0);
    $output = Artisan::output();
    preg_match('/^([a-f0-9]{64})$/m', $output, $m);
    $newSecret = $m[1] ?? null;
    $oldFingerprint = substr(hash('sha256', 'old-secret-value'), 0, 8);
    expect($newSecret)->not->toBeNull()
        ->and(substr_count($output, $newSecret))->toBe(1)
        ->and($output)->toContain('shown once', "<fingerprint:{$oldFingerprint}>", '"expires_at": "2026-09-07T11:30:00+00:00"', 'keep verifying until 2026-09-07T11:30:00+00:00')
        ->and($output)->not->toContain('old-secret-value');

    $block = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($block[(string) $t['connection']][0])->toBe(['secret' => '<new-secret>', 'expires_at' => null])
        ->and($block[(string) $t['connection']][1]['expires_at'])->toBe('2026-09-07T11:30:00+00:00');

    $audit = OperatorAudit::query()->forTenant($t['tenantId'])->sole();
    expect($audit->operation)->toBe(OperatorAuditOperation::WebhookSecretRotated)
        ->and($audit->connection_id)->toBe($t['connection'])
        ->and($audit->actor_id)->toBe((int) $t['operator']->id)
        ->and($audit->before_summary['previous_fingerprints'])->toBe([$oldFingerprint])
        ->and($audit->after_summary['new_fingerprint'])->toBe(substr(hash('sha256', $newSecret), 0, 8))
        ->and($audit->after_summary['overlap_minutes'])->toBe(90)
        ->and(json_encode($audit->before_summary).json_encode($audit->after_summary))->not->toContain('old-secret-value', $newSecret);

    // Config is untouched: the operator pastes the printed list.
    expect(config("people-connector.webhook.secrets.{$t['connection']}"))->toBe('old-secret-value');
});

test('a rotation of a connection from another tenant is refused and nothing is written', function (): void {
    $t = secretRotateTenant('Rotate Tenant');
    $other = secretRotateTenant('Other Rotate Tenant');

    expect(secretRotateRun($t, $other['connection']))->toBe(1)
        ->and(Artisan::output())->toContain('not found in the current tenant')
        ->and(Artisan::output())->not->toMatch('/^[a-f0-9]{64}$/m')
        ->and(OperatorAudit::query()->count())->toBe(0);
});

test('an unauthorized operator gets the authorization failure and no rotation', function (): void {
    $t = secretRotateTenant('Rotate Tenant');
    secretRotateAuthz(false);

    expect(secretRotateRun($t, $t['connection']))->toBe(1)
        ->and(Artisan::output())->toContain('lacks the capability')
        ->and(OperatorAudit::query()->count())->toBe(0);

    secretRotateAuthz(true);
    $outside = secretRotateTenant('Outside Rotate Tenant');
    expect(Artisan::call('connector:webhook:secret:rotate', ['connection' => $t['connection'], '--tenant' => $t['tenantId'], '--as' => $outside['operator']->id]))->toBe(1)
        ->and(Artisan::output())->toContain('operator inside the current tenant')
        ->and(OperatorAudit::query()->count())->toBe(0);
});

test('the doctor shows the overlap row yellow only while a previous secret is unexpired, and stays healthy', function (): void {
    config()->set('queue.default', 'database');
    $t = secretRotateTenant('Rotate Doctor Tenant');
    $this->travelTo('2026-09-07 10:00:00');
    config()->set("people-connector.webhook.secrets.{$t['connection']}", [
        ['secret' => 'new-secret'],
        ['secret' => 'old-secret', 'expires_at' => '2026-09-07T11:00:00+00:00'],
    ]);

    expect(Artisan::call('connector:doctor', ['--tenant' => $t['tenantId'], '--as' => $t['operator']->id, '--json' => true]))->toBe(0);
    $rows = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks']);
    expect($rows->firstWhere('check', 'webhook_secret_overlap'))->toBe(['check' => 'webhook_secret_overlap', 'status' => 'yellow', 'count' => 1, 'detail' => '1 overlapping, earliest expiry 2026-09-07T11:00:00+00:00']);

    $this->travelTo('2026-09-07 11:00:01');
    expect(Artisan::call('connector:doctor', ['--tenant' => $t['tenantId'], '--as' => $t['operator']->id, '--json' => true]))->toBe(0);
    $rows = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks']);
    expect($rows->firstWhere('check', 'webhook_secret_overlap'))->toBe(['check' => 'webhook_secret_overlap', 'status' => 'green', 'count' => 0, 'detail' => '0 overlapping']);
});
