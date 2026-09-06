<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Jobs\RunIncrementalWorkforceSync;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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
});

afterEach(fn () => app(TenantContext::class)->clear());

function doctorTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return [(int) $tenant->id, (int) $company->id, User::factory()->create(['company_id' => $company->id])];
}

function queueDoctorWebhook(int $tenantId, int $ageSeconds): void
{
    Queue::connection('database')->pushOn(RunIncrementalWorkforceSync::QUEUE, new RunIncrementalWorkforceSync($tenantId, 999));
    DB::table('jobs')->latest('id')->limit(1)->update(['created_at' => now()->subSeconds($ageSeconds)->timestamp]);
}

test('connector doctor reports only this tenants stale webhook delivery and exits red', function (): void {
    [$tenantId, $companyId, $operator] = doctorTenant('Doctor Tenant');
    [$otherTenantId, $otherCompanyId] = doctorTenant('Other Doctor Tenant');

    app(TenantContext::class)->set($tenantId);
    app(ProviderRegistry::class)->register(app(FirstPartyPeopleAdapter::class));
    $targetConnections = app(ProviderConnectionStore::class);
    $targetConnection = $targetConnections->configure(ProviderScope::company($companyId), FirstPartyPeopleAdapter::ID);
    $targetConnections->activate((int) $targetConnection->id);

    app(TenantContext::class)->set($otherTenantId);
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company($otherCompanyId), 'test.other-doctor');
    $connection = $connections->activate((int) $connection->id);
    $identity = app(WorkforceIdentityStore::class)->resolveOrCreateIdentity(
        (int) $connection->id,
        new ExternalReference('test.other-doctor', WorkforceResourceType::Employee, 'OTHER-UNRESOLVED'),
        now(),
    );
    WorkforceEntity::query()->whereKey($identity->workforce_entity_id)->update(['state' => WorkforceEntity::STATE_INACTIVE]);

    queueDoctorWebhook($tenantId, 3601);
    queueDoctorWebhook($tenantId, 3599);
    queueDoctorWebhook($otherTenantId, 7200);

    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id]))->toBe(1)
        ->and(Artisan::output())->toContain('webhook_deliveries', 'red', '1 stale', 'identity_mappings', 'green', '0 unresolved');

    DB::table('jobs')->delete();
    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id, '--json' => true]))->toBe(0);
    $rows = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks']);
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->unique()->all())->toBe(['green']);
});

test('connector doctor checks configured adapters that have no active connection', function (): void {
    [$tenantId, $companyId, $operator] = doctorTenant('Inactive Adapter Tenant');

    app(TenantContext::class)->set($tenantId);
    app(ProviderConnectionStore::class)->configure(ProviderScope::company($companyId), 'test.inactive-doctor');

    expect(Artisan::call('connector:doctor', ['--tenant' => $tenantId, '--as' => $operator->id]))->toBe(1)
        ->and(Artisan::output())->toContain(
            'adapter_conformance',
            'red',
            'adapter_not_active:test.inactive-doctor',
        );
});
