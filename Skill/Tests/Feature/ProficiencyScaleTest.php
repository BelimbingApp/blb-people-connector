<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Events\ProficiencyScalePublished;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Exceptions\ProficiencyScaleStateException;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedScaleImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\SkillCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScaleLevel;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * @return array{int, int} [tenantId, companyEntityId]
 */
function proficiencyScaleFixture(string $tenantName = 'Scale Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $company = WorkforceEntity::query()->create([
        'tenant_id' => (int) $tenant->id,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    return [(int) $tenant->id, (int) $company->id];
}

/**
 * @return list<ProficiencyLevelDraft>
 */
function proficiencyScaleLevels(): array
{
    return [
        new ProficiencyLevelDraft(0, 'Not trained', 'No demonstrated knowledge.', 'No authority.'),
        new ProficiencyLevelDraft(1, 'Aware', 'Explains the steps with guidance.', 'Not qualified to perform.'),
        new ProficiencyLevelDraft(2, 'Competent', 'Works independently.', 'May work alone.'),
    ];
}

test('starter pack installs the ten controlled categories and the published 0-5 scale', function (): void {
    [, $companyEntityId] = proficiencyScaleFixture();

    $result = app(SkillCatalogDefaults::class)->install($companyEntityId);

    expect($result['categories'])->toBe(10)
        ->and($result['scale'])->not->toBeNull()
        ->and($result['scale']->status)->toBe(ProficiencyScaleStatus::Published)
        ->and($result['scale']->version)->toBe(1);

    $levels = $result['scale']->levels()->get();
    expect($levels)->toHaveCount(6)
        ->and($levels->pluck('name')->all())->toBe([
            'Not trained', 'Aware', 'Supervised', 'Competent', 'Advanced', 'Expert / Authoriser',
        ])
        ->and($levels->firstWhere('level', 0)->anchor)->toContain('No demonstrated knowledge')
        ->and($levels->firstWhere('level', 3)->authority)->toContain('not authorised to train')
        ->and($levels->firstWhere('level', 5)->authority)->toContain('Formal authority approval');

    // Idempotent: a second install neither duplicates nor re-versions.
    $again = app(SkillCatalogDefaults::class)->install($companyEntityId);
    expect($again['categories'])->toBe(0)
        ->and($again['scale'])->toBeNull()
        ->and(ProficiencyScale::query()->forCompany((int) app(TenantContext::class)->requireTenantId(), $companyEntityId)->count())->toBe(1);
});

test('a scale cannot move to a sibling company at the model or database layer', function (): void {
    [$tenantId, $companyEntityId] = proficiencyScaleFixture('Scale Owner Guard Tenant');
    $scale = app(ProficiencyScaleStore::class)->draft($companyEntityId, 'core', 'Core', proficiencyScaleLevels());
    $sibling = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    expect(fn () => ProficiencyScale::query()->forCompany($tenantId, $companyEntityId)->update(['company_entity_id' => $sibling->id]))
        ->toThrow(CompanyMoveRefusedException::class, 'would leave its company')
        ->and(fn () => $scale->forceFill(['company_entity_id' => $sibling->id])->save())
        ->toThrow(CompanyMoveRefusedException::class, 'would leave its company');

    // A draft is otherwise freely mutable, so only the owner guard can refuse
    // this. Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => ProficiencyScale::query()
        ->movingCompany('Deliberately bypasses the model layer to prove the database trigger stands on its own.')
        ->forCompany($tenantId, $companyEntityId)
        ->update(['company_entity_id' => $sibling->id])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    expect((int) $scale->refresh()->company_entity_id)->toBe($companyEntityId);
});

test('when both scale guards would refuse, the owner guard is the one that speaks', function (): void {
    // people_connector_skill_proficiency_scales carries two BEFORE UPDATE
    // triggers. The test above deliberately uses a DRAFT so only the owner
    // guard can fire, because that was the one configuration both drivers
    // agreed on -- an honest accommodation, and the reason #37 exists.
    //
    // Measured since (#37): PostgreSQL fires BEFORE row triggers in trigger
    // NAME order, SQLite in REVERSE CREATION order. Both therefore reach the
    // owner guard first, by two mechanisms with nothing to do with each other:
    // pcs_scale_company_owner_guard_trigger sorts before pcs_scale_guard_trigger,
    // and the SQLite owner guard is the last CREATE TRIGGER in the migration.
    //
    // Nothing recorded that either fact was load-bearing, so renaming a
    // trigger reordered PostgreSQL alone and moving a statement reordered
    // SQLite alone, each silently and on one lane only. This pins it.
    [$tenantId, $companyEntityId] = proficiencyScaleFixture('Scale Trigger Order Tenant');
    $store = app(ProficiencyScaleStore::class);
    $scale = $store->draft($companyEntityId, 'core', 'Core', proficiencyScaleLevels());
    $store->publish($companyEntityId, (int) $scale->id);

    $sibling = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    // Published AND moving company with no merge record, so BOTH guards would
    // refuse. Which message surfaces is decided purely by firing order.
    //
    // That both are genuinely armed is not visible from here, and this test
    // does not prove it: the owner guard alone is exercised by 'a scale
    // cannot move to a sibling company at the model or database layer' above,
    // and the immutability guard alone by 'published-scale immutability holds
    // at the database layer against builder and raw writes' below. Without
    // that pair this test would pass just as happily with one trigger missing.
    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => ProficiencyScale::query()
        ->movingCompany('Deliberately bypasses the model layer to prove which database trigger speaks first.')
        ->forCompany($tenantId, $companyEntityId)
        ->update(['company_entity_id' => $sibling->id])))
        ->toThrow(QueryException::class, 'cannot move to another company');

    // Both guards held: the row neither moved nor lost its published status.
    expect((int) $scale->refresh()->company_entity_id)->toBe($companyEntityId)
        ->and($scale->status)->toBe(ProficiencyScaleStatus::Published);
});

test('a published scale refuses mutation of itself and its levels', function (): void {
    [, $companyEntityId] = proficiencyScaleFixture();
    $store = app(ProficiencyScaleStore::class);

    $scale = $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels());
    $store->publish($companyEntityId, (int) $scale->id);
    $scale->refresh();

    expect(fn () => $scale->update(['name' => 'Renamed']))
        ->toThrow(PublishedScaleImmutableException::class);

    $level = $scale->levels()->firstWhere('level', 2);
    expect(fn () => $level->update(['name' => 'Changed meaning']))
        ->toThrow(PublishedScaleImmutableException::class);
    expect(fn () => $level->delete())
        ->toThrow(PublishedScaleImmutableException::class);
    expect(fn () => $scale->levels()->create([
        'tenant_id' => $scale->tenant_id, 'level' => 3, 'name' => 'Extra', 'anchor' => 'x', 'authority' => 'y',
    ]))->toThrow(PublishedScaleImmutableException::class);
    expect(fn () => $scale->delete())
        ->toThrow(PublishedScaleImmutableException::class);

    // The meaning-bearing fields survived every refusal.
    expect($scale->refresh()->name)->toBe('Standard')
        ->and($scale->levels()->count())->toBe(3);
});

test('changing a published scale means drafting and publishing a new version', function (): void {
    Event::fake([ProficiencyScalePublished::class]);
    [, $companyEntityId] = proficiencyScaleFixture();
    $store = app(ProficiencyScaleStore::class);

    $v1 = $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels());
    $store->publish($companyEntityId, (int) $v1->id);

    $v2 = $store->newDraftFrom($companyEntityId, (int) $v1->id);

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe(ProficiencyScaleStatus::Draft)
        ->and($v2->levels()->count())->toBe(3);

    // Draft levels stay editable until publish.
    $v2->levels()->firstWhere('level', 2)->update(['name' => 'Independent']);

    $store->publish($companyEntityId, (int) $v2->id);

    expect($v1->refresh()->status)->toBe(ProficiencyScaleStatus::Retired)
        ->and($v1->retired_at)->not->toBeNull()
        ->and($v2->refresh()->status)->toBe(ProficiencyScaleStatus::Published)
        ->and($store->currentScale($companyEntityId, 'standard')?->id)->toBe($v2->id);

    // v1's historical meaning is intact.
    expect($v1->levels()->firstWhere('level', 2)->name)->toBe('Competent');

    Event::assertDispatched(
        ProficiencyScalePublished::class,
        fn (ProficiencyScalePublished $event): bool => $event->version === 2 && $event->retiredPreviousScaleId === (int) $v1->id,
    );
});

test('scale validation refuses gaps, duplicate names, and double drafts', function (): void {
    [, $companyEntityId] = proficiencyScaleFixture();
    $store = app(ProficiencyScaleStore::class);

    expect(fn () => $store->draft($companyEntityId, 'gappy', 'Gappy', [
        new ProficiencyLevelDraft(0, 'A', 'a', 'a'),
        new ProficiencyLevelDraft(2, 'B', 'b', 'b'),
    ]))->toThrow(InvalidSkillCatalogException::class, 'contiguous');

    expect(fn () => $store->draft($companyEntityId, 'dupes', 'Dupes', [
        new ProficiencyLevelDraft(0, 'Same', 'a', 'a'),
        new ProficiencyLevelDraft(1, 'Same', 'b', 'b'),
    ]))->toThrow(InvalidSkillCatalogException::class, 'distinct');

    $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels());
    expect(fn () => $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels()))
        ->toThrow(ProficiencyScaleStateException::class, 'open draft');
});

test('drafts can be discarded but published scales cannot; tenancy bounds every lookup', function (): void {
    [, $companyEntityId] = proficiencyScaleFixture('Scale Tenant A');
    $store = app(ProficiencyScaleStore::class);

    $draft = $store->draft($companyEntityId, 'temp', 'Temp', proficiencyScaleLevels());
    $store->discardDraft($companyEntityId, (int) $draft->id);
    expect(ProficiencyScale::query()->forCompany((int) app(TenantContext::class)->requireTenantId(), $companyEntityId)->count())->toBe(0);

    $published = $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels());
    $store->publish($companyEntityId, (int) $published->id);
    expect(fn () => $store->discardDraft($companyEntityId, (int) $published->id))
        ->toThrow(ProficiencyScaleStateException::class);

    // Another tenant cannot even find this scale, let alone retire it.
    $tenantB = createTenant(['name' => 'Scale Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    expect(fn () => $store->retire($companyEntityId, (int) $published->id))
        ->toThrow(Exception::class, 'not found');
});

test('published-scale immutability holds at the database layer against builder and raw writes', function (): void {
    [, $companyEntityId] = proficiencyScaleFixture('DB Guard Tenant');
    $store = app(ProficiencyScaleStore::class);

    $scale = $store->draft($companyEntityId, 'standard', 'Standard', proficiencyScaleLevels());
    $store->publish($companyEntityId, (int) $scale->id);
    $scaleId = (int) $scale->id;

    // None of these touch Eloquent model events; only the DB triggers stand.
    // Each runs inside its own savepoint (nested transaction): on Postgres a
    // trigger abort poisons the enclosing test transaction otherwise.
    $bypass = fn (callable $write): callable => fn () => DB::transaction($write);

    expect($bypass(fn () => ProficiencyScale::query()->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')->whereKey($scaleId)->update(['name' => 'SILENTLY RENAMED'])))
        ->toThrow(QueryException::class);
    expect($bypass(fn () => ProficiencyScaleLevel::query()->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')->where('scale_id', $scaleId)->where('level', 2)->update(['name' => 'Rewritten'])))
        ->toThrow(QueryException::class);
    expect($bypass(fn () => DB::table('people_connector_skill_proficiency_scale_levels')
        ->where('scale_id', $scaleId)->where('level', 0)->update(['name' => 'Not assessed'])))
        ->toThrow(QueryException::class);
    expect($bypass(fn () => ProficiencyScaleLevel::query()->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')->insert([
        'tenant_id' => $scale->tenant_id, 'scale_id' => $scaleId, 'level' => 3,
        'name' => 'Injected', 'anchor' => 'x', 'authority' => 'y',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
    expect($bypass(fn () => ProficiencyScaleLevel::query()->withoutCompanyScope('Deliberately bypasses the model layer to prove the database trigger stands on its own.')->where('scale_id', $scaleId)->where('level', 1)->delete()))
        ->toThrow(QueryException::class);
    expect($bypass(fn () => DB::table('people_connector_skill_proficiency_scales')->where('id', $scaleId)->delete()))
        ->toThrow(QueryException::class);

    $scale->refresh();
    expect($scale->name)->toBe('Standard')
        ->and($scale->levels()->count())->toBe(3)
        ->and($scale->levels()->firstWhere('level', 0)->name)->toBe('Not trained');
});

test('company axis: a sibling company cannot publish, draft from, or retire this scale', function (): void {
    [$tenantId, $companyEntityIdA] = proficiencyScaleFixture('Scale Company Axis Tenant');
    $store = app(ProficiencyScaleStore::class);
    $draft = $store->draft($companyEntityIdA, 'standard', 'Standard', proficiencyScaleLevels());

    $companyB = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    expect(fn () => $store->publish((int) $companyB->id, (int) $draft->id))
        ->toThrow(SkillCatalogRecordNotFoundException::class);
    expect(fn () => $store->newDraftFrom((int) $companyB->id, (int) $draft->id))
        ->toThrow(SkillCatalogRecordNotFoundException::class);
    expect(fn () => $store->discardDraft((int) $companyB->id, (int) $draft->id))
        ->toThrow(SkillCatalogRecordNotFoundException::class);

    expect($draft->refresh()->status)->toBe(ProficiencyScaleStatus::Draft);
});
