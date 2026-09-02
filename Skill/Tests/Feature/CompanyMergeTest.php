<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceMergeConflictException;
use App\Domains\PeopleConnector\Connector\Models\CompanyOwnedModels;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Self-contained on purpose: Pest test functions are global once their file
// loads, so a helper borrowed from another test file exists only when that
// file happened to load first. This file must pass, and fail, on its own.

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function companyMergeCompanyReference(string $externalId): ExternalReference
{
    return new ExternalReference('test.people', WorkforceResourceType::Company, $externalId);
}

/**
 * Two synchronized companies in one tenant, and the ids a merge needs.
 *
 * @return array{int, int, int, int} [tenantId, connectionId, oldEntityId, newEntityId]
 */
function companyMergeFixture(): array
{
    [$tenant] = createTenantWithCompany(['name' => 'Merge Catalog Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->activate((int) $connections->configure(ProviderScope::tenant(), 'test.people')->id);
    $projections = app(WorkforceProjectionStore::class);
    $identities = app(WorkforceIdentityStore::class);
    $observedAt = new DateTimeImmutable('2026-08-30T09:00:00+00:00');

    foreach ([['COMPANY-OLD', 'Old Company'], ['COMPANY-NEW', 'New Company']] as [$externalId, $name]) {
        $projections->upsert((int) $connection->id, new WorkforceCompany(
            companyMergeCompanyReference($externalId),
            $name,
            true,
            $observedAt,
        ));
    }

    return [
        (int) $tenant->id,
        (int) $connection->id,
        (int) $identities->resolve((int) $connection->id, companyMergeCompanyReference('COMPANY-OLD'))->id,
        (int) $identities->resolve((int) $connection->id, companyMergeCompanyReference('COMPANY-NEW'))->id,
    ];
}

function companyMergeRun(int $connectionId): void
{
    app(WorkforceIdentityStore::class)->merge(
        $connectionId,
        companyMergeCompanyReference('COMPANY-OLD'),
        companyMergeCompanyReference('COMPANY-NEW'),
        new DateTimeImmutable('2026-08-30T10:00:00+00:00'),
        new WorkforceProvenance('identity_merge', 'catalog-merge-review'),
    );
}

function companyMergeSkillDraft(SkillCategory $category): SkillDraft
{
    return new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: null,
        evidenceGuide: null,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    );
}

/** @return list<ProficiencyLevelDraft> */
function companyMergeLevels(): array
{
    return [
        new ProficiencyLevelDraft(0, 'Not trained', 'No demonstrated knowledge.', 'No authority.'),
        new ProficiencyLevelDraft(1, 'Aware', 'Explains the steps with guidance.', 'Not qualified to perform.'),
        new ProficiencyLevelDraft(2, 'Competent', 'Works independently.', 'May work alone.'),
    ];
}

test('the merge rewrites every model that owns rows through company_entity_id, derived rather than listed', function (): void {
    $targets = CompanyOwnedModels::owningCompanyThrough('company_entity_id');

    // The three the hand-kept list had, and the three it was missing.
    foreach ([
        WorkforceOrganizationUnitProjection::class, WorkforcePositionProjection::class, WorkforceEmployeeProjection::class,
        SkillCategory::class, Skill::class, ProficiencyScale::class,
    ] as $model) {
        expect($targets)->toContain($model);
    }

    // Everything company-owned either owns through that column, is a
    // company itself, or inherits from a parent row that does.
    foreach (CompanyOwnedModels::all() as $model) {
        $owner = (new $model)->companyOwnerColumn();
        expect($owner === 'company_entity_id' || $owner === 'workforce_entity_id' || $owner === null)->toBeTrue($model);
    }
});

test('a company merge carries the superseded company\'s catalog to the survivor instead of orphaning it', function (): void {
    [$tenantId, $connectionId, $old, $new] = companyMergeFixture();
    $catalog = app(SkillCatalogStore::class);
    $scales = app(ProficiencyScaleStore::class);

    $category = $catalog->defineCategory($old, 'safety', 'Safety');
    $skill = $catalog->defineSkill($old, companyMergeSkillDraft($category));
    $scale = $scales->draft($old, 'core', 'Core', companyMergeLevels());

    companyMergeRun($connectionId);

    // Reproduced first without the fix: all three counts were 0 under the
    // survivor and the rows still pointed at the merged entity — not wrong,
    // invisible.
    expect(SkillCategory::query()->forCompany($tenantId, $new)->count())->toBe(1)
        ->and(Skill::query()->forCompany($tenantId, $new)->count())->toBe(1)
        ->and(ProficiencyScale::query()->forCompany($tenantId, $new)->count())->toBe(1)
        ->and((int) $category->refresh()->company_entity_id)->toBe($new)
        ->and((int) $skill->refresh()->company_entity_id)->toBe($new)
        ->and((int) $scale->refresh()->company_entity_id)->toBe($new)
        ->and($scale->levels()->count())->toBe(3)
        ->and(Skill::query()->forCompany($tenantId, $old)->count())->toBe(0);
});

test('a company merge that would collide on a unique catalog code is refused whole', function (): void {
    [$tenantId, $connectionId, $old, $new] = companyMergeFixture();
    $catalog = app(SkillCatalogStore::class);

    $oldCategory = $catalog->defineCategory($old, 'safety', 'Old Safety');
    $newCategory = $catalog->defineCategory($new, 'safety', 'New Safety');
    $catalog->defineSkill($old, companyMergeSkillDraft($oldCategory));
    $catalog->defineSkill($new, companyMergeSkillDraft($newCategory));

    expect(fn () => companyMergeRun($connectionId))
        ->toThrow(WorkforceMergeConflictException::class, 'collides');

    // Rolled back whole: nothing moved, nothing merged.
    expect(Skill::query()->forCompany($tenantId, $old)->count())->toBe(1)
        ->and(SkillCategory::query()->forCompany($tenantId, $old)->count())->toBe(1)
        ->and(Skill::query()->forCompany($tenantId, $new)->count())->toBe(1);
});

test('a company merge carries a published scale too, and only a merge may move it', function (): void {
    [$tenantId, $connectionId, $old, $new] = companyMergeFixture();
    $scales = app(ProficiencyScaleStore::class);
    $scale = $scales->publish($old, (int) $scales->draft($old, 'core', 'Core', companyMergeLevels())->id);
    expect($scale->status)->toBe(ProficiencyScaleStatus::Published);

    // Reproduced first (review of #34): PublishedScaleImmutableException,
    // the whole merge refused, category and skill not moved, entity never
    // marked merged. Any company that had ever published a scale could not
    // be merged.
    companyMergeRun($connectionId);

    expect((int) $scale->refresh()->company_entity_id)->toBe($new)
        ->and($scale->status)->toBe(ProficiencyScaleStatus::Published)
        ->and($scale->levels()->count())->toBe(3)
        ->and(ProficiencyScale::query()->forCompany($tenantId, $new)->count())->toBe(1);

    // The exemption is the merge and nothing wider: a published scale still
    // refuses a move to a company that is not its owner's survivor, at the
    // model layer and, with the model layer stepped around, at the database.
    $sibling = (int) app(WorkforceIdentityStore::class)->resolve($connectionId, companyMergeCompanyReference('COMPANY-NEW'))->id;
    $stranger = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => WorkforceResourceType::Company->value,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    expect($sibling)->toBe($new)
        ->and(fn () => $scale->movingCompany('Contract check: a stated move must still meet the immutability guard.')->forceFill(['company_entity_id' => $stranger->id])->save())
        ->toThrow(PublishedScaleImmutableException::class)
        ->and(fn () => DB::transaction(fn () => ProficiencyScale::query()
            ->movingCompany('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
            ->forCompany($tenantId, $new)
            ->update(['company_entity_id' => $stranger->id])))
        ->toThrow(QueryException::class);
});
