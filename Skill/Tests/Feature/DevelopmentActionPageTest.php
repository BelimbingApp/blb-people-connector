<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction\Index;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function developmentPageGrant(User $user, string ...$capabilities): void
{
    foreach ($capabilities as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $user->company_id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }
}

test('the development action route requires view capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Action Page Tenant'], ['name' => 'Action Page Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    developmentPageGrant($viewer,
        'people-connector.skill.development-action.view',
        'people-connector.skill.hr.view',
    );

    $this->actingAs($viewer)->get(route('people-connector.skill.development-actions.index'))->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('people-connector.skill.development-actions.index'))->assertForbidden();

    setupAuthzRoles();
    $platformAdmin = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $platformAdmin->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'core_admin')->valueOrFail('id'),
    ]);
    $this->actingAs($platformAdmin)->get(route('people-connector.skill.development-actions.index'))->assertForbidden();
});

test('viewers cannot create or transition development actions', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Action Viewer Tenant'], ['name' => 'Action Viewer Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    developmentPageGrant($viewer,
        'people-connector.skill.development-action.view',
        'people-connector.skill.hr.view',
    );

    Livewire::actingAs($viewer)->test(Index::class)->call('propose')->assertForbidden();
});
