<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction\Index;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('the development action route requires view capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Action Page Tenant'], ['name' => 'Action Page Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $viewer->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people-connector.skill.development-action.view',
        'is_allowed' => true,
    ]);

    $this->actingAs($viewer)->get(route('people-connector.skill.development-actions.index'))->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('people-connector.skill.development-actions.index'))->assertForbidden();
});

test('viewers cannot create or transition development actions', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Action Viewer Tenant'], ['name' => 'Action Viewer Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $viewer->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people-connector.skill.development-action.view',
        'is_allowed' => true,
    ]);

    expect(fn () => Livewire::actingAs($viewer)->test(Index::class)->call('propose'))
        ->toThrow(AuthorizationDeniedException::class);
});
