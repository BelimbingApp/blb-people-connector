<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Livewire\Reassessment\Index;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function reassessmentPageGrant(User $user, string ...$capabilities): void
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

test('the reassessment route requires view capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reassess Page Tenant'], ['name' => 'Reassess Page Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    reassessmentPageGrant($viewer,
        'people-connector.skill.reassessment.view',
        'people-connector.skill.hr.view',
    );

    $this->actingAs($viewer)->get(route('people-connector.skill.reassessment.index'))->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('people-connector.skill.reassessment.index'))->assertForbidden();

    setupAuthzRoles();
    $platformAdmin = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $platformAdmin->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'core_admin')->valueOrFail('id'),
    ]);
    $this->actingAs($platformAdmin)->get(route('people-connector.skill.reassessment.index'))->assertForbidden();
});

test('viewers cannot open or cancel reassessment requests', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reassess Viewer Tenant'], ['name' => 'Reassess Viewer Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    reassessmentPageGrant($viewer,
        'people-connector.skill.reassessment.view',
        'people-connector.skill.hr.view',
    );

    Livewire::actingAs($viewer)->test(Index::class)->call('openManual')->assertForbidden();
    Livewire::actingAs($viewer)->test(Index::class)->call('cancel', 1)->assertForbidden();
});
