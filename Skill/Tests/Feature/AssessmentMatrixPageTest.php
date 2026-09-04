<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Livewire\Assessment\Matrix;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function assessmentPageCompanyEntity(int $tenantId, string $name, ?int $platformCompanyId = null): int
{
    $entity = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    $connection = ProviderConnection::query()->firstOrCreate([
        'tenant_id' => $tenantId,
        'scope_key' => $platformCompanyId === null ? 'tenant' : 'company:'.$platformCompanyId,
        'provider_id' => 'test.people',
    ], ['company_id' => $platformCompanyId, 'status' => 'active']);

    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $entity->id,
        'provider_id' => 'test.people',
        'resource_type' => 'company',
        'external_id' => 'company-'.$entity->id,
        'external_id_hash' => hash('sha256', 'company-'.$entity->id),
        'state' => 'active',
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);

    WorkforceCompanyProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $entity->id,
        'source_identity_id' => $identity->id,
        'name' => $name,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);

    return (int) $entity->id;
}

test('the assessment matrix route requires the view capability', function (): void {
    $admin = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    assessmentPageCompanyEntity($tenantId, 'Assess Co', (int) $admin->company_id);

    PrincipalCapability::query()->create([
        'company_id' => $admin->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'capability_key' => 'people-connector.skill.assessment.view',
        'is_allowed' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('people-connector.skill.assessment.matrix'))
        ->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(route('people-connector.skill.assessment.matrix'))
        ->assertForbidden();
});

test('saveMatrix refuses viewers without manage capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Matrix View Tenant'], ['name' => 'Matrix View Co']);
    app(TenantContext::class)->set((int) $tenant->id);

    $viewer = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people-connector.skill.assessment.view',
        'is_allowed' => true,
    ]);
    assessmentPageCompanyEntity((int) $tenant->id, 'Matrix View Workforce', (int) $company->id);

    expect(fn () => Livewire::actingAs($viewer)->test(Matrix::class)->call('saveMatrix'))
        ->toThrow(AuthorizationDeniedException::class);
});
