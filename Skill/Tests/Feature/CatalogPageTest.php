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
use App\Domains\PeopleConnector\Skill\Livewire\Catalog\Index;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Provision a synchronized company projection for the given tenant, the way
 * the adapter work (#26/#27) will: entity + identity + typed projection.
 */
function catalogPageCompanyEntity(int $tenantId, string $name = 'SBG Manufacturing'): int
{
    $entity = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    $connection = ProviderConnection::query()->firstOrCreate([
        'tenant_id' => $tenantId,
        'scope_key' => 'tenant',
        'provider_id' => 'test.people',
    ], ['status' => 'active']);

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

function catalogPageViewer(): User
{
    $user = createAdminUser();
    $viewer = User::factory()->create(['company_id' => $user->company_id]);

    PrincipalCapability::query()->create([
        'company_id' => $viewer->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people-connector.skill.catalog.view',
        'is_allowed' => true,
    ]);

    return $viewer;
}

test('the catalog page states honestly that no company is synchronized yet', function (): void {
    $admin = createAdminUser();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('No company workforce data is synchronized yet');
});

test('HR can install the starter pack and administer the catalog end to end', function (): void {
    $admin = createAdminUser();
    $companyEntityId = catalogPageCompanyEntity((int) app(TenantContext::class)->currentTenantId());

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('installStarterPack')
        ->set('tab', 'skills')
        ->call('startSkill')
        ->set('skillForm.code', 'forklift.operation')
        ->set('skillForm.name', 'Forklift Operation')
        ->set('skillForm.definition', 'Operates a forklift to the approved standard.')
        ->call('saveSkill')
        ->assertHasNoErrors()
        ->assertSee('forklift.operation');

    $skill = Skill::query()->sole();
    expect($skill->company_entity_id)->toBe($companyEntityId)
        ->and($skill->active)->toBeTrue();

    // Server-side validation surfaces as a form error, not a crash.
    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('startSkill')
        ->set('skillForm.code', 'forklift.operation')
        ->set('skillForm.name', 'Duplicate')
        ->set('skillForm.definition', 'Duplicate code.')
        ->call('saveSkill')
        ->assertHasErrors('skillForm');
});

test('a viewer can read the catalog but every manage action is refused', function (): void {
    $admin = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    $companyEntityId = catalogPageCompanyEntity($tenantId);
    app(SkillCatalogDefaults::class)->install($companyEntityId);

    $viewer = catalogPageViewer();
    app(TenantContext::class)->set($tenantId);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->set('tab', 'scale')
        ->assertSee('Not trained')
        ->assertSee('Expert / Authoriser')
        ->assertDontSee('New skill');

    expect(fn () => Livewire::actingAs($viewer)->test(Index::class)->call('installStarterPack'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => Livewire::actingAs($viewer)->test(Index::class)->call('startSkill'))
        ->toThrow(AuthorizationDeniedException::class);
});

test('the page never leaks another tenant catalog', function (): void {
    $admin = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    $companyEntityId = catalogPageCompanyEntity($tenantId, 'Tenant A Co');
    app(SkillCatalogDefaults::class)->install($companyEntityId);

    $tenantB = createTenant(['name' => 'Page Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    catalogPageCompanyEntity((int) $tenantB->id, 'Tenant B Co');

    $adminB = createAdminUser(); // fresh company + tenant context of its own

    Livewire::actingAs($adminB)
        ->test(Index::class)
        ->assertDontSee('Tenant A Co');
});

test('the route requires the view capability', function (): void {
    $admin = createAdminUser();

    $this->actingAs($admin)
        ->get(route('people-connector.skill.catalog.index'))
        ->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(route('people-connector.skill.catalog.index'))
        ->assertForbidden();
});
