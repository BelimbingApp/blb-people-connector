<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
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
use Illuminate\Support\Facades\Schema;

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

/**
 * The exemption on a non-draft scale is exactly "owner only, from an entity
 * already recorded as merged into the new owner". Each case below is run at
 * the database layer with raw SQL, which bypasses everything but the trigger,
 * so the plpgsql function and the SQLite WHEN clause are tested against the
 * same list; the model layer is checked where it adds a different message.
 */
test('the non-draft scale exemption is no wider than the merge', function (): void {
    [$tenantId, $connectionId, $a, $b] = companyMergeFixture();
    $scales = app(ProficiencyScaleStore::class);
    $published = $scales->publish($a, (int) $scales->draft($a, 'core', 'Core', companyMergeLevels())->id);
    $retired = $scales->retire($a, (int) $scales->publish($a, (int) $scales->draft($a, 'aux', 'Aux', companyMergeLevels())->id)->id);
    $c = (int) WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => WorkforceResourceType::Company->value,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ])->id;
    $table = $published->getTable();
    $raw = fn (int $scaleId, array $set): int => DB::table($table)->where('id', $scaleId)->update($set);
    // Two BEFORE UPDATE triggers sit on this table and fire in an order the
    // drivers do not agree on, so the message is asserted only where the
    // owner guard permits the write and the scale guard alone must refuse.
    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    $refused = function (callable $write, ?string $because = null): void {
        expect(fn () => DB::transaction($write))->toThrow(QueryException::class, $because);
    };
    $owner = fn (int $scaleId): int => (int) DB::table($table)->where('id', $scaleId)->value('company_entity_id');

    // Before any merged state is written: owner-only moves are refused in
    // both directions, at both layers.
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $b]));
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $c]));
    expect(fn () => $published->movingCompany('boundary check')->forceFill(['company_entity_id' => $b])->save())
        ->toThrow(PublishedScaleImmutableException::class);

    // A is recorded as merged into C. A move to B — the wrong survivor — is
    // refused; owner and content together are refused; owner alone to C is
    // permitted, for the published and the retired scale alike.
    WorkforceEntity::query()->whereKey($a)->update(['state' => WorkforceEntity::STATE_MERGED, 'merged_into_entity_id' => $c]);
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $b]));
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $c, 'name' => 'Renamed']), 'immutable');
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $c, 'status' => 'retired']), 'immutable');
    expect(fn () => $published->fresh()->movingCompany('boundary check')->forceFill(['company_entity_id' => $c, 'name' => 'Renamed'])->save())
        ->toThrow(PublishedScaleImmutableException::class);
    expect($raw((int) $published->id, ['company_entity_id' => $c]))->toBe(1)
        ->and($raw((int) $retired->id, ['company_entity_id' => $c]))->toBe(1)
        ->and($owner((int) $published->id))->toBe($c)
        ->and($owner((int) $retired->id))->toBe($c);

    // The reverse move afterwards is refused: C is not merged into A.
    $refused(fn () => $raw((int) $published->id, ['company_entity_id' => $a]));
    $refused(fn () => $raw((int) $retired->id, ['company_entity_id' => $a]));

    // Content stays immutable at C, so the exemption bought nothing else.
    $refused(fn () => $raw((int) $published->id, ['name' => 'Renamed']), 'immutable');
    expect($owner((int) $published->id))->toBe($c)
        ->and((string) DB::table($table)->where('id', $published->id)->value('name'))->toBe('Core');
});

/**
 * @return array{int, int, int, int, Skill} [tenantId, connectionId, departmentEntityId, ownerEntityId, skill]
 */
function companyMergeSkillWithReferences(): array
{
    [$tenantId, $connectionId, $old, $new] = companyMergeFixture();
    $projections = app(WorkforceProjectionStore::class);
    $identities = app(WorkforceIdentityStore::class);
    $observedAt = new DateTimeImmutable('2026-08-30T09:00:00+00:00');
    $company = companyMergeCompanyReference('COMPANY-OLD');
    $unit = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::OrganizationUnit, $id);
    $employee = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::Employee, $id);

    foreach (['UNIT-OLD', 'UNIT-NEW'] as $id) {
        $projections->upsert($connectionId, new WorkforceOrganizationUnit($unit($id), $company, $id, true, $observedAt, $observedAt));
    }
    foreach (['EMP-OLD', 'EMP-NEW'] as $id) {
        $projections->upsert($connectionId, new WorkforceEmployee($employee($id), $company, $id, true, $observedAt, $observedAt));
    }

    $department = (int) $identities->resolve($connectionId, $unit('UNIT-OLD'))->id;
    $owner = (int) $identities->resolve($connectionId, $employee('EMP-OLD'))->id;
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($old, 'safety', 'Safety');
    $skill = $catalog->defineSkill($old, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $category->id,
        scope: SkillScope::Department,
        criticalClassification: null,
        evidenceGuide: null,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
        departmentEntityId: $department,
        ownerEmployeeEntityId: $owner,
    ));

    return [$tenantId, $connectionId, $department, $owner, $skill];
}

test('merging an organization unit or an employee carries the skill references that point at it', function (): void {
    [, $connectionId, $department, $owner, $skill] = companyMergeSkillWithReferences();
    $identities = app(WorkforceIdentityStore::class);
    $unit = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::OrganizationUnit, $id);
    $employee = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::Employee, $id);
    $provenance = new WorkforceProvenance('identity_merge', 'reference-merge-review');

    // Reproduced first (review of #34, blb-people-connector#35): with the
    // hand-listed branches, both columns kept pointing at the merged entity.
    $identities->merge($connectionId, $unit('UNIT-OLD'), $unit('UNIT-NEW'), new DateTimeImmutable('2026-08-30T10:00:00+00:00'), $provenance);
    $identities->merge($connectionId, $employee('EMP-OLD'), $employee('EMP-NEW'), new DateTimeImmutable('2026-08-30T11:00:00+00:00'), $provenance);

    $newDepartment = (int) $identities->resolve($connectionId, $unit('UNIT-NEW'))->id;
    $newOwner = (int) $identities->resolve($connectionId, $employee('EMP-NEW'))->id;

    expect($newDepartment)->not->toBe($department)
        ->and($newOwner)->not->toBe($owner)
        ->and((int) $skill->refresh()->department_entity_id)->toBe($newDepartment)
        ->and((int) $skill->owner_employee_entity_id)->toBe($newOwner);
});

/**
 * A fresh company entity in the tenant, for a move that no merge sanctions.
 */
function companyMergeStranger(int $tenantId): int
{
    return (int) WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => WorkforceResourceType::Company->value,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ])->id;
}

test('categories and skills refuse the wrong survivor at the database, with the model layer stepped around', function (): void {
    // Scales carry two guards, so weakening one leaves the suite green there.
    // Categories and skills carry one, and until this test nothing asserted
    // its destination pin (blb-people-connector#39): widening it on every
    // definition kept the suite green on both drivers.
    [$tenantId, $connectionId, $a, $b] = companyMergeFixture();
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($a, 'safety', 'Safety');
    $skill = $catalog->defineSkill($a, companyMergeSkillDraft($category));
    $c = companyMergeStranger($tenantId);
    $rows = [
        [$category->getTable(), (int) $category->id],
        [$skill->getTable(), (int) $skill->id],
    ];
    $raw = fn (string $table, int $id, int $owner): int => DB::table($table)->where('id', $id)->update(['company_entity_id' => $owner]);
    $ownerOf = fn (string $table, int $id): int => (int) DB::table($table)->where('id', $id)->value('company_entity_id');
    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    $refused = fn (callable $write) => expect(fn () => DB::transaction($write))->toThrow(QueryException::class, 'cannot move to another company');

    foreach ($rows as [$table, $id]) {
        // No merge recorded: neither destination is allowed.
        $refused(fn () => $raw($table, $id, $b));
        $refused(fn () => $raw($table, $id, $c));
    }

    // A merged into C: B is the wrong survivor and is refused; C is permitted;
    // the reverse move afterwards is refused.
    WorkforceEntity::query()->whereKey($a)->update(['state' => WorkforceEntity::STATE_MERGED, 'merged_into_entity_id' => $c]);

    foreach ($rows as [$table, $id]) {
        $refused(fn () => $raw($table, $id, $b));
        expect($raw($table, $id, $c))->toBe(1)
            ->and($ownerOf($table, $id))->toBe($c);
        $refused(fn () => $raw($table, $id, $a));
    }
});

test('the scale merge arm pins every column except the owner and updated_at', function (): void {
    // Enumerated columns are a list someone must remember to extend
    // (blb-people-connector#38). This inverts the default: every column on
    // the table must appear below either as pinned — a change alongside the
    // owner move is refused — or as deliberately permitted. A new column
    // fails this test until it is placed in one of the two.
    [$tenantId, $connectionId, $a] = companyMergeFixture();
    $scales = app(ProficiencyScaleStore::class);
    $scale = $scales->publish($a, (int) $scales->draft($a, 'core', 'Core', companyMergeLevels())->id);
    $c = companyMergeStranger($tenantId);
    WorkforceEntity::query()->whereKey($a)->update(['state' => WorkforceEntity::STATE_MERGED, 'merged_into_entity_id' => $c]);
    $table = $scale->getTable();

    $pinned = [
        'id' => 999_999,
        'tenant_id' => 999_999,
        'code' => 'core.moved',
        'name' => 'Renamed',
        'version' => 99,
        'status' => 'retired',
        'published_at' => '2020-01-01 00:00:00',
        'retired_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00',
    ];
    $permitted = ['company_entity_id', 'updated_at'];

    expect(array_values(array_diff(Schema::getColumnListing($table), array_keys($pinned), $permitted)))
        ->toBe([], 'every column must be pinned or explicitly permitted');

    foreach ($pinned as $column => $changed) {
        // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $scale->id)->update(['company_entity_id' => $c, $column => $changed])))
            ->toThrow(QueryException::class, 'immutable', "column [{$column}] rode along with the owner change");
    }

    expect(DB::table($table)->where('id', $scale->id)->update(['company_entity_id' => $c, 'updated_at' => '2030-01-01 00:00:00']))->toBe(1);
});

test('the scale retire arm pins every column except its lifecycle timestamps', function (): void {
    // Published → retired changes status and retired_at; Eloquent also writes
    // updated_at. Every other column must be named as pinned, so this arm
    // cannot silently drift when the table gains a column (#55).
    [, , $companyEntityId, $mergeTarget] = companyMergeFixture();
    $scales = app(ProficiencyScaleStore::class);
    $scale = $scales->publish($companyEntityId, (int) $scales->draft($companyEntityId, 'retire', 'Retire', companyMergeLevels())->id);
    $table = $scale->getTable();

    // Let the owner guard admit this one otherwise-valid owner transition, so
    // the scale guard — not a missing merge fact — proves retirement pins it.
    WorkforceEntity::query()->whereKey($companyEntityId)->update([
        'state' => WorkforceEntity::STATE_MERGED,
        'merged_into_entity_id' => $mergeTarget,
    ]);

    $pinned = [
        'id' => 999_999,
        'tenant_id' => 999_999,
        'company_entity_id' => $mergeTarget,
        'code' => 'retire.changed',
        'name' => 'Changed',
        'version' => 99,
        'published_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00',
    ];
    $permitted = ['status', 'retired_at', 'updated_at'];

    expect(array_values(array_diff(Schema::getColumnListing($table), array_keys($pinned), $permitted)))
        ->toBe([], 'every column must be pinned or explicitly permitted during retirement');

    foreach ($pinned as $column => $changed) {
        // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $scale->id)->update([
            'status' => ProficiencyScaleStatus::Retired->value,
            'retired_at' => '2030-01-01 00:00:00',
            $column => $changed,
        ])))->toThrow(QueryException::class, 'immutable', "column [{$column}] rode along with retirement");
    }
});
