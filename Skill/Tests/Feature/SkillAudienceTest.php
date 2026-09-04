<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillAudienceAssignmentException;
use App\Domains\PeopleConnector\Skill\Livewire\Assessment\Matrix;
use App\Domains\PeopleConnector\Skill\Models\SkillActorBinding;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Skill\Services\SkillAudienceAssignmentStore;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function skillAudienceRole(User $user, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();

    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

/** @return array{int, ProviderConnection} */
function skillAudienceCompany(int $tenantId, Company $platformCompany, string $name): array
{
    $companyEntity = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId,
        'company_id' => $platformCompany->id,
        'scope_key' => 'company:'.$platformCompany->id,
        'active_scope_key' => 'company:'.$platformCompany->id,
        'provider_id' => 'test.people',
        'status' => ProviderConnection::STATUS_ACTIVE,
    ]);
    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $companyEntity->id,
        'provider_id' => 'test.people',
        'resource_type' => 'company',
        'external_id' => 'company-'.$companyEntity->id,
        'external_id_hash' => hash('sha256', 'company-'.$companyEntity->id),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);
    WorkforceCompanyProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $companyEntity->id,
        'source_identity_id' => $identity->id,
        'name' => $name,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);

    return [(int) $companyEntity->id, $connection];
}

function skillAudienceEntity(int $tenantId, string $type): int
{
    return (int) WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ])->id;
}

function skillAudienceEmployee(
    int $tenantId,
    int $companyEntityId,
    ProviderConnection $connection,
    string $name,
    ?int $organizationEntityId = null,
    ?int $managerEntityId = null,
    ?int $departmentHeadEntityId = null,
): WorkforceEmployeeProjection {
    $employeeEntityId = skillAudienceEntity($tenantId, 'employee');
    $userEntityId = skillAudienceEntity($tenantId, 'user');
    $externalId = 'employee-'.$employeeEntityId;
    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $employeeEntityId,
        'provider_id' => 'test.people',
        'resource_type' => 'employee',
        'external_id' => $externalId,
        'external_id_hash' => hash('sha256', $externalId),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);

    return WorkforceEmployeeProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $employeeEntityId,
        'source_identity_id' => $identity->id,
        'company_entity_id' => $companyEntityId,
        'user_entity_id' => $userEntityId,
        'organization_entity_id' => $organizationEntityId,
        'manager_entity_id' => $managerEntityId,
        'department_head_entity_id' => $departmentHeadEntityId,
        'display_name' => $name,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);
}

test('platform administration does not implicitly become connector HR', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Audience Tenant'], ['name' => 'Audience Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    [$companyEntityId, $connection] = skillAudienceCompany((int) $tenant->id, $company, 'Audience Workforce');
    $first = skillAudienceEmployee((int) $tenant->id, $companyEntityId, $connection, 'First Worker');
    $second = skillAudienceEmployee((int) $tenant->id, $companyEntityId, $connection, 'Second Worker');

    $hr = User::factory()->create(['company_id' => $company->id]);
    skillAudienceRole($hr, 'people_hr');

    $platformAdmin = User::factory()->create(['company_id' => $company->id]);
    skillAudienceRole($platformAdmin, 'core_admin');

    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toEqualCanonicalizing([(int) $first->workforce_entity_id, (int) $second->workforce_entity_id])
        ->and(app(SkillAudience::class)->visibleDevelopmentActionEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toEqualCanonicalizing([(int) $first->workforce_entity_id, (int) $second->workforce_entity_id])
        ->and(fn () => app(SkillAudience::class)->authorizeAudience(
            $platformAdmin,
            'people-connector.skill.development-action.view',
        ))->toThrow(AuthorizationDeniedException::class);

    $this->actingAs($platformAdmin)
        ->get(route('people-connector.skill.assessment.matrix'))
        ->assertForbidden();
});

test('connector menus are visible only to their deep People audiences', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Menu Audience Tenant'],
        ['name' => 'Menu Audience Company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);

    $users = collect([
        'hr' => 'people_hr',
        'hod' => 'people_hod',
        'assessor' => 'people_assessor',
        'employee' => 'people_employee',
        'platform' => 'core_admin',
    ])->map(function (string $role) use ($company): User {
        $user = User::factory()->create(['company_id' => $company->id]);
        skillAudienceRole($user, $role);

        return $user;
    });

    $skillItems = collect((require __DIR__.'/../../Config/menu.php')['items'])
        ->mapWithKeys(fn (array $item): array => [$item['id'] => MenuItem::fromArray($item)]);
    $trainingItems = collect((require __DIR__.'/../../../Training/Config/menu.php')['items'])
        ->mapWithKeys(fn (array $item): array => [$item['id'] => MenuItem::fromArray($item)]);
    $checker = app(MenuAccessChecker::class);

    expect($checker->canView($skillItems->get('people.skills'), $users->get('platform')))->toBeFalse()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('platform')))->toBeFalse()
        ->and($checker->canView($trainingItems->get('people.training-events'), $users->get('platform')))->toBeFalse()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('hr')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('hr')))->toBeTrue()
        ->and($checker->canView($trainingItems->get('people.training-events'), $users->get('hr')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('hod')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('hod')))->toBeTrue()
        ->and($checker->canView($trainingItems->get('people.training-events'), $users->get('hod')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('assessor')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('assessor')))->toBeTrue()
        ->and($checker->canView($trainingItems->get('people.training-events'), $users->get('assessor')))->toBeFalse()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('employee')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('employee')))->toBeTrue()
        ->and($checker->canView($trainingItems->get('people.training-events'), $users->get('employee')))->toBeFalse();
});

test('HOD assessor and employee audiences resolve department assignment and self without sibling leakage', function (): void {
    [$tenant, $companyA] = createTenantWithCompany(['name' => 'Scoped Audience Tenant'], ['name' => 'Company A']);
    $companyB = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Company B']);
    app(TenantContext::class)->set((int) $tenant->id);
    [$companyAEntityId, $connectionA] = skillAudienceCompany((int) $tenant->id, $companyA, 'Workforce A');
    [$companyBEntityId, $connectionB] = skillAudienceCompany((int) $tenant->id, $companyB, 'Workforce B');

    $departmentA = skillAudienceEntity((int) $tenant->id, 'organization_unit');
    $departmentB = skillAudienceEntity((int) $tenant->id, 'organization_unit');
    $head = skillAudienceEmployee((int) $tenant->id, $companyAEntityId, $connectionA, 'Department Head', $departmentA);
    $teamWorker = skillAudienceEmployee(
        (int) $tenant->id,
        $companyAEntityId,
        $connectionA,
        'Team Worker',
        $departmentA,
        (int) $head->workforce_entity_id,
        (int) $head->workforce_entity_id,
    );
    $otherDepartment = skillAudienceEmployee(
        (int) $tenant->id,
        $companyAEntityId,
        $connectionA,
        'Other Department',
        $departmentB,
    );
    $siblingCompany = skillAudienceEmployee((int) $tenant->id, $companyBEntityId, $connectionB, 'Sibling Company Worker');

    $hr = User::factory()->create(['company_id' => $companyA->id]);
    $hod = User::factory()->create(['company_id' => $companyA->id]);
    $assessor = User::factory()->create(['company_id' => $companyA->id]);
    $employee = User::factory()->create(['company_id' => $companyA->id]);
    skillAudienceRole($hr, 'people_hr');
    skillAudienceRole($hod, 'people_hod');
    skillAudienceRole($assessor, 'people_assessor');
    skillAudienceRole($employee, 'people_employee');

    $assignments = app(SkillAudienceAssignmentStore::class);
    $assignments->confirmActor($hr, $hod, $companyAEntityId, (int) $head->workforce_entity_id, 'review:hod-link');
    $assignments->confirmActor($hr, $employee, $companyAEntityId, (int) $teamWorker->workforce_entity_id, 'review:self-link');
    $assignments->assignAssessor(
        $hr,
        $assessor,
        $companyAEntityId,
        (int) $teamWorker->workforce_entity_id,
        'review:assessor-assignment',
    );
    expect(fn () => $assignments->assignAssessor(
        $hr,
        $assessor,
        $companyAEntityId,
        (int) $siblingCompany->workforce_entity_id,
        'review:invalid-sibling-assignment',
    ))->toThrow(InvalidSkillAudienceAssignmentException::class);

    $audience = app(SkillAudience::class);
    expect($audience->visibleEmployeeEntityIds($hod, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->workforce_entity_id])
        ->and($audience->visibleDevelopmentActionEmployeeEntityIds($hod, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->workforce_entity_id])
        ->and($audience->visibleEmployeeEntityIds($assessor, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->workforce_entity_id])
        ->and($audience->visibleEmployeeEntityIds($employee, $companyAEntityId, manage: false))
        ->toBe([(int) $teamWorker->workforce_entity_id])
        ->and($audience->visibleEmployeeEntityIds($hod, $companyBEntityId, manage: true))
        ->toBe([])
        ->and($audience->visibleDevelopmentActionEmployeeEntityIds($hod, $companyBEntityId, manage: true))
        ->toBe([])
        ->and($audience->allowedCompanies($hr, 'people-connector.skill.assessment.view'))
        ->toBe([$companyAEntityId => 'Workforce A']);

    $audience->authorizeAssessmentSubmission($assessor, $companyAEntityId, (int) $teamWorker->workforce_entity_id);
    $audience->authorizeHodVerification($hod, $companyAEntityId, (int) $teamWorker->workforce_entity_id);
    $audience->authorizeAssessmentFinalization($hod, $companyAEntityId, (int) $teamWorker->workforce_entity_id);

    expect(fn () => $audience->authorizeHodVerification(
        $assessor,
        $companyAEntityId,
        (int) $teamWorker->workforce_entity_id,
    ))->toThrow(AuthorizationDeniedException::class);

    expect(fn () => $audience->visibleEmployeeEntityIds($employee, $companyAEntityId, manage: true))
        ->toThrow(AuthorizationDeniedException::class);

    expect($otherDepartment->workforce_entity_id)->not->toBe($teamWorker->workforce_entity_id)
        ->and($siblingCompany->workforce_entity_id)->not->toBe($teamWorker->workforce_entity_id);

    Livewire::actingAs($hod)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);

    Livewire::actingAs($assessor)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);

    Livewire::actingAs($employee)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);
});

test('revocation and tenant changes invalidate a previously confirmed self binding', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Binding Tenant A'], ['name' => 'Binding Company A']);
    app(TenantContext::class)->set((int) $tenantA->id);
    [$companyEntityId, $connection] = skillAudienceCompany((int) $tenantA->id, $companyA, 'Binding Workforce');
    $worker = skillAudienceEmployee((int) $tenantA->id, $companyEntityId, $connection, 'Bound Worker');
    $hr = User::factory()->create(['company_id' => $companyA->id]);
    $employee = User::factory()->create(['company_id' => $companyA->id]);
    skillAudienceRole($hr, 'people_hr');
    skillAudienceRole($employee, 'people_employee');

    $store = app(SkillAudienceAssignmentStore::class);
    $store->confirmActor($hr, $employee, $companyEntityId, (int) $worker->workforce_entity_id, 'review:binding');
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))
        ->toBe([(int) $worker->workforce_entity_id]);

    $store->revokeActor($hr, $companyEntityId, (int) $employee->id, 'review:revocation');
    $revoked = SkillActorBinding::query()
        ->forCompany((int) $tenantA->id, $companyEntityId)
        ->where('platform_user_id', $employee->id)
        ->sole();
    expect($revoked->revoked_by_user_id)->toBe((int) $hr->id)
        ->and($revoked->revocation_reference)->toBe('review:revocation')
        ->and(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([]);

    $store->confirmActor($hr, $employee, $companyEntityId, (int) $worker->workforce_entity_id, 'review:reconfirmed');

    $worker->user_entity_id = null;
    $worker->save();
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([]);

    $tenantB = createTenant(['name' => 'Binding Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([])
        ->and(app(SkillAudience::class)->visibleDevelopmentActionEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toBe([]);
});
