<?php

declare(strict_types=1);

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Notifications\TransitionNotification;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Database\Seeders\RequirementProfileWorkflowSeeder;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfilePublished;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfileRetired;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidRequirementProfileException;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\RequirementProfileNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore;
use App\Domains\PeopleConnector\Skill\Services\RequirementResolver;
use App\Domains\PeopleConnector\Skill\Services\SkillAudienceAssignmentStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function requirementEntity(int $tenantId, string $type, ?int $companyEntityId = null): WorkforceEntity
{
    $entity = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    if (($type === 'organization_unit' || $type === 'position') && $companyEntityId !== null) {
        $connection = ProviderConnection::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'scope_key' => 'tenant',
                'provider_id' => 'test-provider',
            ],
            [
                'label' => 'Test Connection',
                'status' => ProviderConnection::STATUS_ACTIVE,
            ]
        );

        $externalId = 'test-id-'.$entity->id;
        $identity = ExternalIdentity::query()->create([
            'tenant_id' => $tenantId,
            'connection_id' => $connection->id,
            'workforce_entity_id' => $entity->id,
            'provider_id' => 'test-provider',
            'resource_type' => $type,
            'external_id' => $externalId,
            'external_id_hash' => hash('sha256', $externalId),
            'state' => ExternalIdentity::STATE_ACTIVE,
            'effective_from' => now(),
            'last_observed_at' => now(),
        ]);

        if ($type === 'organization_unit') {
            WorkforceOrganizationUnitProjection::create([
                'tenant_id' => $tenantId,
                'workforce_entity_id' => $entity->id,
                'source_identity_id' => $identity->id,
                'company_entity_id' => $companyEntityId,
                'name' => 'Test Department',
                'active' => true,
                'effective_at' => now(),
                'observed_at' => now(),
            ]);
        }

        if ($type === 'position') {
            WorkforcePositionProjection::create([
                'tenant_id' => $tenantId,
                'workforce_entity_id' => $entity->id,
                'source_identity_id' => $identity->id,
                'company_entity_id' => $companyEntityId,
                'name' => 'Test Position',
                'active' => true,
                'effective_at' => now(),
                'observed_at' => now(),
            ]);
        }
    }

    return $entity;
}

/**
 * @return array{int, int, Skill, Skill}
 */
function requirementFixture(string $tenantName = 'Requirements Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $company = requirementEntity((int) $tenant->id, 'company');
    $catalogStore = app(SkillCatalogStore::class);
    $category = $catalogStore->defineCategory((int) $company->id, 'safety', 'Safety');

    $skillA = $catalogStore->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    $skillB = $catalogStore->defineSkill((int) $company->id, new SkillDraft(
        code: 'packing',
        name: 'Product Packing',
        definition: 'Packs products to specification',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return [(int) $tenant->id, (int) $company->id, $skillA, $skillB];
}

function simpleProfileDraft(Skill $skillA, Skill $skillB, ?DateTimeInterface $effectiveDate = null): RequirementProfileDraft
{
    return new RequirementProfileDraft(
        code: 'warehouse.operator',
        name: 'Warehouse Operator',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Company),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 60.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 40.0,
            ),
        ],
        effectiveDate: $effectiveDate,
    );
}

test('a requirement profile carries workbook parity fields and fires lifecycle events', function (): void {
    Event::fake([RequirementProfilePublished::class, RequirementProfileRetired::class]);
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();

    $store = app(RequirementProfileStore::class);

    expect(fn () => DB::table('people_connector_skill_requirement_profiles')->insert([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyEntityId,
        'code' => 'hostile.published',
        'name' => 'Hostile published insert',
        'version' => 1,
        'status' => RequirementProfileStatus::Published->value,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    $profile = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));

    $itemCount = RequirementItem::query()->forCompany($tenantId, $companyEntityId)->where('profile_id', $profile->id)->count();
    $selectorCount = RequirementProfileSelector::query()->forCompany($tenantId, $companyEntityId)->where('profile_id', $profile->id)->count();

    expect($profile->code)->toBe('warehouse.operator')
        ->and($profile->name)->toBe('Warehouse Operator')
        ->and($profile->version)->toBe(1)
        ->and($profile->status)->toBe(RequirementProfileStatus::Draft)
        ->and($itemCount)->toBe(2)
        ->and($selectorCount)->toBe(1);

    $profile = $store->publish($companyEntityId, (int) $profile->id);

    expect($profile->status)->toBe(RequirementProfileStatus::Published)
        ->and($profile->published_at)->not->toBeNull();

    Event::assertDispatched(RequirementProfilePublished::class);

    $profile = $store->retire($companyEntityId, (int) $profile->id);

    expect($profile->status)->toBe(RequirementProfileStatus::Retired)
        ->and($profile->retired_at)->not->toBeNull();

    Event::assertDispatched(RequirementProfileRetired::class);
});

test('published profiles are immutable and versioning creates new drafts', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $v1 = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));
    $v1 = $store->publish($companyEntityId, (int) $v1->id);

    expect(fn () => $v1->update(['name' => 'Renamed']))
        ->toThrow(PublishedRequirementImmutableException::class, 'cannot be modified');

    $firstItem = RequirementItem::query()->forCompany($tenantId, $companyEntityId)->where('profile_id', $v1->id)->first();
    expect(fn () => $firstItem->update(['required_level' => 5]))
        ->toThrow(PublishedRequirementImmutableException::class);

    $v2 = $store->newDraftFrom($companyEntityId, (int) $v1->id);

    $v1ItemCount = RequirementItem::query()->forCompany($tenantId, $companyEntityId)->where('profile_id', $v1->id)->count();
    $v2ItemCount = RequirementItem::query()->forCompany($tenantId, $companyEntityId)->where('profile_id', $v2->id)->count();

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe(RequirementProfileStatus::Draft)
        ->and($v2->code)->toBe($v1->code)
        ->and($v2ItemCount)->toBe($v1ItemCount);

    $v2 = $store->publish($companyEntityId, (int) $v2->id);

    expect($v1->refresh()->status)->toBe(RequirementProfileStatus::Retired)
        ->and($v1->retired_at)->not->toBeNull()
        ->and($v2->status)->toBe(RequirementProfileStatus::Published);
});

test('validation enforces weight totaling 100% before publishing', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $draft = new RequirementProfileDraft(
        code: 'bad.weights',
        name: 'Bad Weights',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 30.0,
            ),
        ],
    );

    $profile = $store->draft($companyEntityId, $draft);

    expect(fn () => $store->publish($companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'must total 100%');
});

test('department targeting matches employees by organization unit entity', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $deptA = requirementEntity($tenantId, 'organization_unit', $companyEntityId);
    $deptB = requirementEntity($tenantId, 'organization_unit', $companyEntityId);

    $draftA = new RequirementProfileDraft(
        code: 'dept.a.profile',
        name: 'Department A Profile',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $deptA->id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    $profileA = $store->draft($companyEntityId, $draftA);
    $store->publish($companyEntityId, (int) $profileA->id);

    $employeeInDeptA = [
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $deptA->id,
    ];

    $employeeInDeptB = [
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $deptB->id,
    ];

    $resultA = $resolver->resolve($employeeInDeptA);
    expect($resultA['profile'])->not->toBeNull()
        ->and($resultA['profile']->code)->toBe('dept.a.profile')
        ->and($resultA['matched_selectors'])->toContain('department');

    $resultB = $resolver->resolve($employeeInDeptB);
    expect($resultB['profile'])->toBeNull()
        ->and($resultB['explanation'])->toContain('No published requirement profile matches');
});

test('effective dating returns the most recent applicable profile', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $older = new RequirementProfileDraft(
        code: 'dated.profile',
        name: 'Older Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
    );

    $newer = new RequirementProfileDraft(
        code: 'dated.profile',
        name: 'Newer Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    $v1 = $store->draft($companyEntityId, $older);
    Carbon::setTestNow('2024-01-10 12:00:00');
    $v1 = $store->publish($companyEntityId, (int) $v1->id);

    $v2 = $store->newDraftFrom($companyEntityId, (int) $v1->id);
    $v2->update(['name' => 'Newer Profile']);
    Carbon::setTestNow('2024-01-20 12:00:00');
    $v2 = $store->publish($companyEntityId, (int) $v2->id);

    $employee = ['company_entity_id' => $companyEntityId];

    // Publish day must resolve to v2 without throwing: inclusive retired_at
    // overlapped the successor's start for the whole calendar day.
    $resultPublishDay = $resolver->resolve($employee, Carbon::parse('2024-01-20'));
    expect($resultPublishDay['profile'])->not->toBeNull()
        ->and($resultPublishDay['profile']->version)->toBe(2);

    $resultCurrent = $resolver->resolve($employee, Carbon::parse('2024-01-25'));
    expect($resultCurrent['profile'])->not->toBeNull()
        ->and($resultCurrent['profile']->version)->toBe(2)
        ->and($resultCurrent['profile']->name)->toBe('Newer Profile');

    $resultOld = $resolver->resolve($employee, Carbon::parse('2024-01-15'));
    expect($resultOld['profile'])->not->toBeNull()
        ->and($resultOld['profile']->version)->toBe(1)
        ->and($resultOld['profile']->name)->toBe('Older Profile');
});

test('effective_date March policy published July resolves for March as-of', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $marchPolicy = new RequirementProfileDraft(
        code: 'march.effective',
        name: 'March Effective Policy',
        effectiveDate: Carbon::parse('2024-03-01'),
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
    );

    $v1 = $store->draft($companyEntityId, $marchPolicy);
    Carbon::setTestNow('2024-07-15 12:00:00');
    $v1 = $store->publish($companyEntityId, (int) $v1->id);

    $employee = ['company_entity_id' => $companyEntityId];

    // The effective date itself must resolve on both drivers — SQLite stores
    // cast dates as 'Y-m-d H:i:s', and comparing that string to a bare day
    // without DATE() skips the boundary.
    $resultEffectiveDay = $resolver->resolve($employee, Carbon::parse('2024-03-01'));
    expect($resultEffectiveDay['profile'])->not->toBeNull()
        ->and($resultEffectiveDay['profile']->code)->toBe('march.effective');

    $resultMarch = $resolver->resolve($employee, Carbon::parse('2024-03-15'));
    expect($resultMarch['profile'])->not->toBeNull()
        ->and($resultMarch['profile']->code)->toBe('march.effective')
        ->and($resultMarch['profile']->name)->toBe('March Effective Policy');

    $resultJuly = $resolver->resolve($employee, Carbon::parse('2024-07-20'));
    expect($resultJuly['profile'])->not->toBeNull()
        ->and($resultJuly['profile']->code)->toBe('march.effective');

    $resultFebruary = $resolver->resolve($employee, Carbon::parse('2024-02-29'));
    expect($resultFebruary['profile'])->toBeNull();
});

test('company isolation: sibling company cannot address profiles', function (): void {
    [$tenantId, $companyAId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $profileA = $store->draft($companyAId, simpleProfileDraft($skillA, $skillB));
    $profileA = $store->publish($companyAId, (int) $profileA->id);

    $companyB = requirementEntity($tenantId, 'company');

    expect(fn () => $store->publish((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);

    expect(fn () => $store->retire((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);

    expect(fn () => $store->discardDraft((int) $companyB->id, (int) $profileA->id))
        ->toThrow(RequirementProfileNotFoundException::class);
});

test('company isolation: department selector cannot reference sibling company organization unit', function (): void {
    [$tenantId, $companyAId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $companyB = requirementEntity($tenantId, 'company');

    $deptEntity = requirementEntity($tenantId, 'organization_unit', $companyAId);
    $deptA = WorkforceOrganizationUnitProjection::query()
        ->forCompany($tenantId, $companyAId)
        ->where('workforce_entity_id', $deptEntity->id)
        ->first();

    $crossCompanyProfile = new RequirementProfileDraft(
        code: 'cross.company',
        name: 'Cross Company Profile',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $deptA->workforce_entity_id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    expect(fn () => $store->draft((int) $companyB->id, $crossCompanyProfile))
        ->toThrow(InvalidRequirementProfileException::class, 'does not belong to this company');
});

test('tenant isolation: another tenant cannot see or address profiles', function (): void {
    [$tenantA, $companyAId, $skillA, $skillB] = requirementFixture('Tenant A');
    $store = app(RequirementProfileStore::class);

    $profileA = $store->draft($companyAId, simpleProfileDraft($skillA, $skillB));

    $tenantB = createTenant(['name' => 'Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    $countA = RequirementProfile::query()
        ->withoutCompanyScope('Deliberately counting tenant-wide to assert cross-tenant isolation')
        ->forTenant($tenantA)
        ->count();
    $countB = RequirementProfile::query()
        ->withoutCompanyScope('Deliberately counting tenant-wide to assert cross-tenant isolation')
        ->forTenant((int) $tenantB->id)
        ->count();

    expect($countA)->toBe(1)
        ->and($countB)->toBe(0);
});

test('database immutability guards prevent silent mutation of published profiles', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $profile = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));
    $profile = $store->publish($companyEntityId, (int) $profile->id);

    expect(fn () => DB::transaction(fn () => RequirementProfile::query()
        ->withoutCompanyScope('Deliberately bypasses model layer to test database trigger')
        ->whereKey($profile->id)
        ->update(['name' => 'Renamed by raw query'])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => RequirementItem::query()
        ->withoutCompanyScope('Deliberately bypasses model layer to test database trigger')
        ->where('profile_id', $profile->id)
        ->update(['required_level' => 5])))
        ->toThrow(QueryException::class);

    expect($profile->refresh()->name)->toBe('Warehouse Operator');
});

test('validation refuses duplicate skills, bad sequences, and negative weights', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $duplicateSkills = new RequirementProfileDraft(
        code: 'duplicate',
        name: 'Duplicate Skills',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 2,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 50.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $duplicateSkills))
        ->toThrow(InvalidRequirementProfileException::class, 'only once');

    $badSequence = new RequirementProfileDraft(
        code: 'badseq',
        name: 'Bad Sequence',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 50.0,
            ),
            new RequirementItemDraft(
                skillId: (int) $skillB->id,
                sequence: 5,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 50.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $badSequence))
        ->toThrow(InvalidRequirementProfileException::class, 'contiguous');

    $negativeWeight = new RequirementProfileDraft(
        code: 'negweight',
        name: 'Negative Weight',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: -10.0,
            ),
        ],
    );

    expect(fn () => $store->draft($companyEntityId, $negativeWeight))
        ->toThrow(InvalidRequirementProfileException::class, 'non-negative');
});

test('multi-selector profiles require all selectors to match', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $dept = requirementEntity($tenantId, 'organization_unit', $companyEntityId);

    $position = requirementEntity($tenantId, 'position', $companyEntityId);

    $multiSelector = new RequirementProfileDraft(
        code: 'multi.selector',
        name: 'Multi Selector Profile',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $dept->id),
            new RequirementSelectorDraft(SelectorType::Position, null, (int) $position->id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skillA->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Critical,
                weightPercent: 100.0,
            ),
        ],
    );

    $profile = $store->draft($companyEntityId, $multiSelector);
    $store->publish($companyEntityId, (int) $profile->id);

    $matchesBoth = $resolver->resolve([
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $dept->id,
        'position_entity_id' => (int) $position->id,
    ]);

    expect($matchesBoth['profile'])->not->toBeNull()
        ->and($matchesBoth['matched_selectors'])->toHaveCount(2);

    $matchesDeptOnly = $resolver->resolve([
        'company_entity_id' => $companyEntityId,
        'department_entity_id' => (int) $dept->id,
    ]);

    expect($matchesDeptOnly['profile'])->toBeNull()
        ->and($matchesDeptOnly['explanation'])->toContain('Failed on selector');
});

test('company isolation: sibling company cannot address profiles or child records', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);

    $profile = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB));
    $profileId = (int) $profile->id;

    $siblingCompanyEntity = requirementEntity($tenantId, 'company');

    expect(fn () => RequirementProfile::query()->forCompany($tenantId, (int) $siblingCompanyEntity->id)->findOrFail($profileId))
        ->toThrow(ModelNotFoundException::class);

    $items = RequirementItem::query()->forCompany($tenantId, (int) $siblingCompanyEntity->id)->where('profile_id', $profileId)->get();
    expect($items)->toHaveCount(0);

    $selectors = RequirementProfileSelector::query()->forCompany($tenantId, (int) $siblingCompanyEntity->id)->where('profile_id', $profileId)->get();
    expect($selectors)->toHaveCount(0);
});

test('as-of dating uses published_at/retired_at interval, not null effective_date', function (): void {
    [$tenantId, $companyEntityId, $skillA, $skillB] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $resolver = app(RequirementResolver::class);

    $v1 = $store->draft($companyEntityId, simpleProfileDraft($skillA, $skillB, effectiveDate: null));
    Carbon::setTestNow('2024-01-10 12:00:00');
    $v1 = $store->publish($companyEntityId, (int) $v1->id);

    $v2 = $store->newDraftFrom($companyEntityId, (int) $v1->id);
    Carbon::setTestNow('2024-01-20 12:00:00');
    $v2 = $store->publish($companyEntityId, (int) $v2->id);

    expect($v1->refresh()->status)->toBe(RequirementProfileStatus::Retired)
        ->and($v1->retired_at)->not->toBeNull()
        ->and($v1->effective_date)->toBeNull()
        ->and($v2->effective_date)->toBeNull();

    $historicalResult = $resolver->resolve(['company_entity_id' => $companyEntityId], Carbon::parse('2024-01-15'));

    expect($historicalResult['profile'])->not->toBeNull()
        ->and((int) $historicalResult['profile']->id)->toBe((int) $v1->id)
        ->and($historicalResult['profile']->version)->toBe(1);
});

test('merging a position rewrites requirement profile selectors that target it', function (): void {
    [$tenantId] = requirementFixture();
    $store = app(RequirementProfileStore::class);
    $projections = app(WorkforceProjectionStore::class);
    $identities = app(WorkforceIdentityStore::class);

    $connection = ProviderConnection::query()->firstOrCreate(
        [
            'tenant_id' => $tenantId,
            'scope_key' => 'tenant',
            'provider_id' => 'test.people',
        ],
        [
            'label' => 'Merge Connection',
            'status' => ProviderConnection::STATUS_ACTIVE,
        ],
    );
    $at = new DateTimeImmutable('2026-09-03T00:00:00+00:00');
    $companyRef = new ExternalReference('test.people', WorkforceResourceType::Company, 'REQ-MERGE-CO');
    $projections->upsert((int) $connection->id, new WorkforceCompany($companyRef, 'Merge Co', true, $at));
    $mergeCompanyId = (int) $identities->resolve((int) $connection->id, $companyRef)->id;

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($mergeCompanyId, 'merge', 'Merge');
    $skill = $catalog->defineSkill($mergeCompanyId, new SkillDraft(
        code: 'merge.skill',
        name: 'Merge Skill',
        definition: 'Merge.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    $pos = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::Position, $id);
    foreach (['POS-OLD', 'POS-NEW'] as $id) {
        $projections->upsert((int) $connection->id, new WorkforcePosition($pos($id), $companyRef, $id, true, $at, $at));
    }
    $oldPositionId = (int) $identities->resolve((int) $connection->id, $pos('POS-OLD'))->id;

    $profile = $store->draft($mergeCompanyId, new RequirementProfileDraft(
        code: 'pos.merge',
        name: 'Position Merge Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Position, null, $oldPositionId)],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skill->id,
                sequence: 1,
                requiredLevel: 2,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
    ));
    $store->publish($mergeCompanyId, (int) $profile->id);

    expect(array_map(
        fn (array $pair): string => $pair[0].'.'.$pair[1]->column,
        DomainModels::referencing(WorkforceResourceType::Position),
    ))->toContain(RequirementProfileSelector::class.'.selector_entity_id');

    $identities->merge(
        (int) $connection->id,
        $pos('POS-OLD'),
        $pos('POS-NEW'),
        $at->modify('+1 hour'),
        new WorkforceProvenance('identity_merge', 'selector-merge-review'),
    );
    $newPositionId = (int) $identities->resolve((int) $connection->id, $pos('POS-NEW'))->id;

    $selector = RequirementProfileSelector::query()
        ->forCompany($tenantId, $mergeCompanyId)
        ->where('profile_id', $profile->id)
        ->firstOrFail();

    expect($newPositionId)->not->toBe($oldPositionId)
        ->and((int) $selector->selector_entity_id)->toBe($newPositionId);
});

test('criticality enum provides workbook priority multipliers', function (): void {
    expect(RequirementCriticality::Critical->multiplier())->toBe(3)
        ->and(RequirementCriticality::Essential->multiplier())->toBe(2)
        ->and(RequirementCriticality::Development->multiplier())->toBe(1);
});

function requirementGovernanceRole(User $user, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
    PrincipalRole::query()->firstOrCreate([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

/** @return array{int, ProviderConnection} */
function requirementGovernanceCompany(int $tenantId, Company $platformCompany): array
{
    $company = requirementEntity($tenantId, 'company');
    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId,
        'company_id' => $platformCompany->id,
        'scope_key' => 'company:'.$platformCompany->id,
        'active_scope_key' => 'company:'.$platformCompany->id,
        'provider_id' => 'governance.test',
        'status' => ProviderConnection::STATUS_ACTIVE,
    ]);
    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $company->id,
        'provider_id' => 'governance.test',
        'resource_type' => 'company',
        'external_id' => 'company-'.$company->id,
        'external_id_hash' => hash('sha256', 'company-'.$company->id),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);
    WorkforceCompanyProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $company->id,
        'source_identity_id' => $identity->id,
        'name' => $platformCompany->name,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);

    return [(int) $company->id, $connection];
}

function requirementGovernanceEmployee(
    int $tenantId,
    int $companyEntityId,
    ProviderConnection $connection,
    int $departmentEntityId,
): WorkforceEmployeeProjection {
    $employee = requirementEntity($tenantId, 'employee');
    $userEntity = requirementEntity($tenantId, 'user');
    $externalId = 'employee-'.$employee->id;
    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $employee->id,
        'provider_id' => 'governance.test',
        'resource_type' => 'employee',
        'external_id' => $externalId,
        'external_id_hash' => hash('sha256', $externalId),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);

    return WorkforceEmployeeProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $employee->id,
        'source_identity_id' => $identity->id,
        'company_entity_id' => $companyEntityId,
        'user_entity_id' => $userEntity->id,
        'organization_entity_id' => $departmentEntityId,
        'department_head_entity_id' => $employee->id,
        'display_name' => 'Department Head',
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);
}

test('governed profiles require in-scope HOD review and HR approval before publication', function (): void {
    Notification::fake();
    [$tenant, $platformCompany] = createTenantWithCompany(
        ['name' => 'Governance Tenant'],
        ['name' => 'Governance Company'],
    );
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    [$companyEntityId, $connection] = requirementGovernanceCompany($tenantId, $platformCompany);
    $department = requirementEntity($tenantId, 'organization_unit', $companyEntityId);
    $hodEmployee = requirementGovernanceEmployee($tenantId, $companyEntityId, $connection, (int) $department->id);

    $hr = User::factory()->create(['company_id' => $platformCompany->id]);
    $hod = User::factory()->create(['company_id' => $platformCompany->id]);
    $outsider = User::factory()->create(['company_id' => $platformCompany->id]);
    requirementGovernanceRole($hr, 'people_hr');
    requirementGovernanceRole($hod, 'people_hod');
    requirementGovernanceRole($outsider, 'people_hod');
    app(SkillAudienceAssignmentStore::class)->confirmActor(
        $hr,
        $hod,
        $companyEntityId,
        (int) $hodEmployee->workforce_entity_id,
        'review:hod-governance-binding',
    );
    (new RequirementProfileWorkflowSeeder)->run();

    $category = app(SkillCatalogStore::class)->defineCategory($companyEntityId, 'governed', 'Governed');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, new SkillDraft(
        code: 'governed.skill',
        name: 'Governed Skill',
        definition: 'Technical requirement.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $profile = app(RequirementProfileStore::class)->draft($companyEntityId, new RequirementProfileDraft(
        code: 'governed.profile',
        name: 'Governed Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Department, null, (int) $department->id)],
        items: [new RequirementItemDraft(
            skillId: (int) $skill->id,
            sequence: 1,
            requiredLevel: 3,
            criticality: RequirementCriticality::Critical,
            weightPercent: 100.0,
        )],
    ));
    $store = app(RequirementProfileStore::class);

    expect(fn () => $profile->update(['status' => RequirementProfileStatus::PendingHodReview]))
        ->toThrow(PublishedRequirementImmutableException::class, 'must use the governed workflow');
    expect($profile->refresh()->status)->toBe(RequirementProfileStatus::Draft)
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id))->toBeEmpty();

    $draftItem = RequirementItem::query()->forCompany($tenantId, $companyEntityId)
        ->where('profile_id', $profile->id)->firstOrFail();
    DB::table('people_connector_skill_requirement_items')
        ->where('id', $draftItem->id)
        ->update(['required_level' => 6]);
    expect(fn () => $store->submitForReview($hr, $companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'Required level must be between 0 and 5');
    expect($profile->refresh()->status)->toBe(RequirementProfileStatus::Draft)
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id))->toBeEmpty();
    DB::table('people_connector_skill_requirement_items')
        ->where('id', $draftItem->id)
        ->update(['required_level' => 3]);
    $draftItem->update(['weight_percent' => 90]);
    expect(fn () => $store->submitForReview($hr, $companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'weights must total 100%');
    expect($profile->refresh()->status)->toBe(RequirementProfileStatus::Draft)
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id))->toBeEmpty();
    $draftItem->update(['weight_percent' => 100]);

    $profile = $store->submitForReview($hr, $companyEntityId, (int) $profile->id, 'Ready for technical review.');
    expect($profile->status)->toBe(RequirementProfileStatus::PendingHodReview)
        ->and($store->reviewQueue($hod, $companyEntityId)->pluck('id')->all())->toBe([(int) $profile->id])
        ->and($store->reviewQueue($outsider, $companyEntityId))->toBeEmpty()
        ->and(StatusHistory::latest(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id)?->assignees)
        ->toBe([['user_id' => (int) $hod->id]]);
    Notification::assertSentTo(
        $hod,
        TransitionNotification::class,
        fn (TransitionNotification $notification): bool => $notification->model->getKey() === $profile->getKey()
            && $notification->transition->to_code === RequirementProfileStatus::PendingHodReview->value,
    );
    Notification::assertNothingSentTo($outsider);

    expect(fn () => $store->newDraftFrom($companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'already has an open version');

    expect(fn () => DB::transaction(fn (): int => DB::table('people_connector_skill_requirement_items')
        ->where('id', $draftItem->id)
        ->update(['required_level' => 4])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn (): bool => DB::table('people_connector_skill_requirement_profile_selectors')
        ->insert([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'profile_id' => $profile->id,
            'selector_type' => SelectorType::Company->value,
            'created_at' => now(),
            'updated_at' => now(),
        ])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn (): int => DB::table('people_connector_skill_requirement_profiles')
        ->where('id', $profile->id)
        ->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => $store->publishApproved($hr, $companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'No transition defined');

    expect(fn () => $store->approveHod($outsider, $companyEntityId, (int) $profile->id, 'Looks good.'))
        ->toThrow(InvalidRequirementProfileException::class, 'outside the required company or department');

    $profile = $store->approveHod($hod, $companyEntityId, (int) $profile->id, 'Technical requirements verified.');
    Notification::assertSentTo(
        $hr,
        TransitionNotification::class,
        fn (TransitionNotification $notification): bool => $notification->model->getKey() === $profile->getKey()
            && $notification->transition->to_code === RequirementProfileStatus::PendingHrReview->value,
    );
    expect(fn () => $store->publishApproved($hr, $companyEntityId, (int) $profile->id))
        ->toThrow(InvalidRequirementProfileException::class, 'No transition defined');
    $profile = $store->approveHr($hr, $companyEntityId, (int) $profile->id, 'Governance controls verified.');
    expect(fn () => DB::transaction(fn (): int => DB::table('people_connector_skill_requirement_profiles')
        ->where('id', $profile->id)
        ->update([
            'status' => RequirementProfileStatus::Published->value,
            'published_at' => null,
        ])))
        ->toThrow(QueryException::class);
    $profile = $store->publishApproved($hr, $companyEntityId, (int) $profile->id);

    expect($profile->status)->toBe(RequirementProfileStatus::Published)
        ->and($profile->published_at)->not->toBeNull()
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id)->pluck('status')->all())
        ->toBe(['pending_hod_review', 'pending_hr_review', 'approved', 'published'])
        ->and(StatusHistory::latest(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id)?->metadata)
        ->toMatchArray([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'profile_code' => 'governed.profile',
            'profile_version' => 1,
            'capability' => 'people-connector.skill-requirement-publication.approve',
        ]);
    expect(fn () => DB::transaction(fn (): int => DB::table('people_connector_skill_requirement_profiles')
        ->where('id', $profile->id)
        ->update([
            'status' => RequirementProfileStatus::Retired->value,
            'retired_at' => null,
        ])))
        ->toThrow(QueryException::class);

    $revision = $store->newDraftFrom($companyEntityId, (int) $profile->id);
    $revision = $store->submitForReview($hr, $companyEntityId, (int) $revision->id);
    $item = RequirementItem::query()->forCompany($tenantId, $companyEntityId)
        ->where('profile_id', $revision->id)->firstOrFail();
    expect(fn () => $item->update(['required_level' => 4]))
        ->toThrow(PublishedRequirementImmutableException::class);

    $revision = $store->returnByHod($hod, $companyEntityId, (int) $revision->id, 'Clarify the technical evidence.');
    expect($revision->status)->toBe(RequirementProfileStatus::Draft)
        ->and(StatusHistory::latest(RequirementProfile::WORKFLOW_FLOW, (int) $revision->id)?->comment)
        ->toBe('Clarify the technical evidence.');
    $revision->update(['name' => 'Governed Profile Revised']);

    $revision = $store->submitForReview($hr, $companyEntityId, (int) $revision->id);
    $revision = $store->approveHod($hod, $companyEntityId, (int) $revision->id, 'Technical revision accepted.');
    $revision = $store->returnByHr($hr, $companyEntityId, (int) $revision->id, 'Add a governance rationale.');
    expect($revision->status)->toBe(RequirementProfileStatus::Draft)
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $revision->id)
            ->pluck('status')->all())
        ->toBe(['pending_hod_review', 'draft', 'pending_hod_review', 'pending_hr_review', 'draft']);

    $revision = $store->submitForReview($hr, $companyEntityId, (int) $revision->id);
    $revision = $store->approveHod($hod, $companyEntityId, (int) $revision->id, 'Technical review complete.');
    $revision = $store->approveHr($hr, $companyEntityId, (int) $revision->id, 'Governance review complete.');
    Event::fake([RequirementProfilePublished::class, RequirementProfileRetired::class]);
    $revision = $store->publishApproved($hr, $companyEntityId, (int) $revision->id);

    expect($profile->refresh()->status)->toBe(RequirementProfileStatus::Retired)
        ->and($revision->status)->toBe(RequirementProfileStatus::Published)
        ->and(StatusHistory::timeline(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id)->pluck('status')->all())
        ->toBe(['pending_hod_review', 'pending_hr_review', 'approved', 'published', 'retired'])
        ->and(StatusHistory::latest(RequirementProfile::WORKFLOW_FLOW, (int) $profile->id)?->metadata)
        ->toMatchArray([
            'profile_code' => 'governed.profile',
            'profile_version' => 1,
            'replacement_profile_id' => (int) $revision->id,
            'replacement_profile_version' => 2,
            'capability' => 'people-connector.skill-requirement-retirement.approve',
        ])
        ->and(RequirementProfile::query()->forCompany($tenantId, $companyEntityId)
            ->where('code', 'governed.profile')
            ->where('status', RequirementProfileStatus::Published->value)
            ->count())->toBe(1);
    Event::assertDispatched(
        RequirementProfilePublished::class,
        fn (RequirementProfilePublished $event): bool => $event->profileId === (int) $revision->id
            && $event->retiredPreviousProfileId === (int) $profile->id,
    );
    Event::assertDispatched(
        RequirementProfileRetired::class,
        fn (RequirementProfileRetired $event): bool => $event->profileId === (int) $profile->id,
    );

    $notificationUrl = $revision->workflowNotificationUrl();
    expect($notificationUrl)->toBe(route('people-connector.skill.requirement-profiles.show', [
        'profileId' => $revision->id,
    ]));
    $this->actingAs($hr)->get($notificationUrl)
        ->assertOk()
        ->assertSee('Governed Profile Revised')
        ->assertSee('v2');

    $concurrentContender = RequirementProfile::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyEntityId,
        'code' => 'governed.profile',
        'name' => 'Concurrent contender',
        'version' => 99,
        'status' => RequirementProfileStatus::Draft,
    ]);
    foreach ([
        RequirementProfileStatus::PendingHodReview,
        RequirementProfileStatus::PendingHrReview,
        RequirementProfileStatus::Approved,
    ] as $contenderStatus) {
        app(RequirementProfileTransitionAuthority::class)->authorize(
            $concurrentContender,
            $concurrentContender->status,
            $contenderStatus,
        );
        $concurrentContender->update(['status' => $contenderStatus]);
    }
    expect(fn () => DB::table('people_connector_skill_requirement_profiles')
        ->where('id', $concurrentContender->id)
        ->update(['status' => RequirementProfileStatus::Published->value, 'published_at' => now()]))
        ->toThrow(QueryException::class);
});
