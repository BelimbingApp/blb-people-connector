<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Livewire\Catalog\Index;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Provision a synchronized company projection for the given tenant, the way
 * the adapter work (#26/#27) will: entity + identity + typed projection.
 */
function catalogPageCompanyEntity(int $tenantId, string $name = 'SBG Manufacturing', ?int $platformCompanyId = null): int
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

function catalogPageViewer(int $companyId): User
{
    $viewer = User::factory()->create(['company_id' => $companyId]);

    PrincipalCapability::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people-connector.skill.catalog.view',
        'is_allowed' => true,
    ]);

    return $viewer;
}

/**
 * A draft scale on a code of its OWN, not a new version of the standard one.
 *
 * newDraftFrom() opens a draft on the SAME code, and draft()'s open-draft rule
 * is per code -- so it would leave draftNewScaleVersion unable to write even
 * fully un-funnelled, killing the count assertion that is its only detector
 * (#47, measured by opus-5-review-z). A separate code keeps both live.
 */
function catalogPageProbeDraft(int $companyEntityId): ProficiencyScale
{
    return app(ProficiencyScaleStore::class)->draft($companyEntityId, 'probe', 'Probe', [
        new ProficiencyLevelDraft(0, 'None', 'No demonstrated capability.', 'None.'),
        new ProficiencyLevelDraft(1, 'Basic', 'Works with supervision.', 'Supervised.'),
        new ProficiencyLevelDraft(2, 'Full', 'Works unsupervised.', 'Authorised.'),
    ]);
}

test('the catalog page states honestly that no company is synchronized yet', function (): void {
    $admin = createAdminUser();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('No company workforce data is synchronized yet');
});

test('HR can install the starter pack and administer the catalog end to end', function (): void {
    $admin = createAdminUser();
    $companyEntityId = catalogPageCompanyEntity(
        (int) app(TenantContext::class)->currentTenantId(),
        'SBG Manufacturing',
        (int) $admin->company_id,
    );

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

    $skill = Skill::query()
        ->forCompany((int) app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->sole();
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
    $companyEntityId = catalogPageCompanyEntity($tenantId, 'SBG Manufacturing', (int) $admin->company_id);
    app(SkillCatalogDefaults::class)->install($companyEntityId);

    $viewer = catalogPageViewer((int) $admin->company_id);
    app(TenantContext::class)->set($tenantId);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->set('tab', 'scale')
        ->assertSee('Not trained')
        ->assertSee('Expert / Authoriser')
        ->assertDontSee('New skill');

    // authorizedCompanyForManage() has two halves: the manage capability and
    // the company check. Pinning only the company half leaves a view-only HOD
    // able to write to their OWN company's catalog, which the company half
    // permits. Both halves need every action driven through them.
    //
    // Real ids from the viewer's own company, so that with authorizeManage()
    // removed the action would genuinely succeed rather than throw a
    // not-found from the store and satisfy a laxer expectation.
    $category = SkillCategory::query()
        ->forCompany($tenantId, $companyEntityId)->where('code', 'quality')->sole();
    $scale = ProficiencyScale::query()
        ->forCompany($tenantId, $companyEntityId)->where('code', SkillCatalogDefaults::SCALE_CODE)->sole();
    $skill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, new SkillDraft(
        code: 'viewer.probe.skill',
        name: 'Viewer Probe Skill',
        definition: 'Exists so a viewer has a real id to fail against.',
        categoryId: (int) $category->id,
    ));

    $draft = catalogPageProbeDraft($companyEntityId);

    $refused = function (string $action, array $args = []) use ($viewer, $companyEntityId): void {
        expect(fn () => Livewire::actingAs($viewer)->test(Index::class)
            ->set('companyEntityId', $companyEntityId)
            ->call($action, ...$args))
            ->toThrow(AuthorizationDeniedException::class);
    };

    $refused('installStarterPack');
    $refused('startSkill');
    $refused('saveSkill');
    $refused('saveCategory');
    $refused('toggleSkillActive', [(int) $skill->id]);
    $refused('renameCategory', [(int) $category->id, 'Renamed By Viewer']);
    $refused('toggleCategoryActive', [(int) $category->id]);
    $refused('publishScale', [(int) $draft->id]);
    $refused('draftNewScaleVersion', [(int) $scale->id]);

    expect($skill->refresh()->active)->toBeTrue()
        ->and($category->refresh()->name)->not->toBe('Renamed By Viewer')
        ->and($category->active)->toBeTrue()
        ->and($draft->refresh()->status)->toBe(ProficiencyScaleStatus::Draft)
        ->and(ProficiencyScale::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2);
});

test('the page never leaks another tenant catalog', function (): void {
    $admin = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    $companyEntityId = catalogPageCompanyEntity($tenantId, 'Tenant A Co', (int) $admin->company_id);
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

test('an actor in one company cannot reach a sibling company catalog in the same tenant', function (): void {
    $adminAlpha = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    $alphaEntity = catalogPageCompanyEntity($tenantId, 'Alpha Workforce', (int) $adminAlpha->company_id);

    $companyBeta = Company::factory()->create();
    $betaEntity = catalogPageCompanyEntity($tenantId, 'Beta Workforce', (int) $companyBeta->id);
    app(SkillCatalogDefaults::class)->install($betaEntity);
    $betaCategory = SkillCategory::query()
        ->forCompany($tenantId, $betaEntity)->where('code', 'quality')->sole();
    app(SkillCatalogStore::class)->defineSkill($betaEntity, new SkillDraft(
        code: 'beta.secret.process',
        name: 'Beta Secret Process',
        definition: 'Beta-only production standard.',
        categoryId: (int) $betaCategory->id,
    ));

    // The picker offers Alpha only, and Beta's catalog never renders.
    Livewire::actingAs($adminAlpha)
        ->test(Index::class)
        ->assertViewHas('companies', [$alphaEntity => 'Alpha Workforce'])
        ->assertDontSee('Beta Workforce')
        ->assertDontSee('beta.secret.process');

    // Forcing the client-writable property renders an empty catalog, not Beta's…
    Livewire::actingAs($adminAlpha)
        ->test(Index::class)
        ->set('companyEntityId', $betaEntity)
        ->assertDontSee('beta.secret.process');

    // …and every path that would act on Beta 404s.
    Livewire::actingAs($adminAlpha)->test(Index::class)
        ->call('selectCompany', $betaEntity)->assertStatus(404);
    Livewire::actingAs($adminAlpha)->test(Index::class)
        ->set('companyEntityId', $betaEntity)->call('installStarterPack')->assertStatus(404);
    Livewire::actingAs($adminAlpha)->test(Index::class)
        ->set('companyEntityId', $betaEntity)->call('startSkill')->assertStatus(404);

    expect(Skill::query()->forCompany($tenantId, $betaEntity)->where('code', 'beta.secret.process')->sole()->name)
        ->toBe('Beta Secret Process');
});

test('a single-company tenant with a tenant-scoped provider stays visible, then fails closed when a second company appears', function (): void {
    setupAuthzRoles();
    [$tenant, $company] = createTenantWithCompany(['name' => 'Carve-out Tenant'], ['name' => 'Solo Co']);
    app(TenantContext::class)->set((int) $tenant->id);

    $user = User::factory()->create(['company_id' => $company->id]);
    foreach (['people-connector.skill.catalog.view', 'people-connector.skill.catalog.manage'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    $entity = catalogPageCompanyEntity((int) $tenant->id, 'Solo Workforce');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertViewHas('companies', [$entity => 'Solo Workforce']);

    // A second platform company removes the carve-out: unattributable
    // workforce companies fail closed until #21 lands a real mapping.
    Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Second Co']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertViewHas('companies', []);
});

test('every mutating catalog action refuses a company the actor may not act for', function (): void {
    // The component documents authorizedCompanyForManage() as "the single
    // authorization funnel for every mutating action". Nothing failed if an
    // action stopped going through it: the sibling-company test above covers
    // selectCompany, installStarterPack and startSkill, and none of those
    // writes anything.
    $adminAlpha = createAdminUser();
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    catalogPageCompanyEntity($tenantId, 'Alpha Workforce', (int) $adminAlpha->company_id);

    $companyBeta = Company::factory()->create();
    $betaEntity = catalogPageCompanyEntity($tenantId, 'Beta Workforce', (int) $companyBeta->id);
    app(SkillCatalogDefaults::class)->install($betaEntity);

    $betaCategory = SkillCategory::query()
        ->forCompany($tenantId, $betaEntity)->where('code', 'quality')->sole();
    $betaScale = ProficiencyScale::query()
        ->forCompany($tenantId, $betaEntity)->where('code', SkillCatalogDefaults::SCALE_CODE)->sole();
    $betaSkill = app(SkillCatalogStore::class)->defineSkill($betaEntity, new SkillDraft(
        code: 'beta.secret.process',
        name: 'Beta Secret Process',
        definition: 'Beta-only production standard.',
        categoryId: (int) $betaCategory->id,
    ));

    // A draft on its own code: publishScale has something to publish, and
    // the standard scale stays free for draftNewScaleVersion, so both
    // assertions below detect their own leak. (install() leaves the standard
    // scale published, and a draft of the same code would block the other
    // action -- either way one assertion goes dead. See #47.)
    $betaDraft = catalogPageProbeDraft($betaEntity);

    // Beta's REAL ids, deliberately. With a made-up id the action would 404
    // from its own not-found check even with the funnel removed, and this
    // test would pass while proving nothing.
    $refuses = function (string $action, array $args = []) use ($adminAlpha, $betaEntity): void {
        Livewire::actingAs($adminAlpha)->test(Index::class)
            ->set('companyEntityId', $betaEntity)
            ->call($action, ...$args)
            ->assertStatus(404);
    };

    $refuses('saveSkill');
    $refuses('saveCategory');
    $refuses('toggleSkillActive', [(int) $betaSkill->id]);
    $refuses('renameCategory', [(int) $betaCategory->id, 'Renamed By Alpha']);
    $refuses('toggleCategoryActive', [(int) $betaCategory->id]);
    $refuses('publishScale', [(int) $betaDraft->id]);
    $refuses('draftNewScaleVersion', [(int) $betaScale->id]);

    // Nothing moved.
    expect($betaSkill->refresh()->active)->toBeTrue()
        ->and($betaSkill->name)->toBe('Beta Secret Process')
        ->and($betaCategory->refresh()->name)->not->toBe('Renamed By Alpha')
        ->and($betaCategory->active)->toBeTrue()
        ->and($betaDraft->refresh()->status)->toBe(ProficiencyScaleStatus::Draft)
        ->and(ProficiencyScale::query()->forCompany($tenantId, $betaEntity)->count())->toBe(2);
});
