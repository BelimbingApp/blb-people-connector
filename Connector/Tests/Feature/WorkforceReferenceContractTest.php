<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contract that makes forgetting impossible rather than unlikely: every
 * `*_entity_id` column on every model's table is either the row's own
 * identity, the merge pointer, its owning company, or a declared workforce
 * reference — and every declared reference is a real column.
 */
function workforceReferenceUndeclaredColumns(string $model): array
{
    $instance = new $model;
    $exempt = ['workforce_entity_id', 'merged_into_entity_id'];

    if (in_array(CompanyOwned::class, class_uses_recursive($model), true) && $instance->companyOwnerColumn() !== null) {
        $exempt[] = $instance->companyOwnerColumn();
    }

    $declared = $instance instanceof ReferencesWorkforceEntities
        ? array_map(fn (WorkforceReference $reference): string => $reference->column, $instance->workforceReferences())
        : [];

    if (! Schema::hasTable($instance->getTable())) {
        return ['undeclared' => ['table_missing:'.$instance->getTable()], 'declared_but_missing' => []];
    }

    $columns = array_values(array_filter(
        Schema::getColumnListing($instance->getTable()),
        fn (string $column): bool => str_ends_with($column, '_entity_id') && ! in_array($column, $exempt, true),
    ));

    return [
        'undeclared' => array_values(array_diff($columns, $declared)),
        'declared_but_missing' => array_values(array_diff($declared, $columns)),
    ];
}

test('the repository actually contains models to check', function (): void {
    expect(DomainModels::all())->not->toBeEmpty()
        ->and(DomainModels::all())->toContain(Skill::class, WorkforceEmployeeProjection::class);
});

test('every workforce reference column is declared, and every declaration is a column', function (string $model): void {
    expect(workforceReferenceUndeclaredColumns($model))->toBe(['undeclared' => [], 'declared_but_missing' => []]);
})->with(DomainModels::all());

test('the two columns the merge forgot are now declared where the merge reads them', function (): void {
    $forOrganizationUnits = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::OrganizationUnit));
    $forEmployees = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::Employee));

    expect($forOrganizationUnits)->toContain(Skill::class.'.department_entity_id')
        ->and($forOrganizationUnits)->toContain(RequirementProfileSelector::class.'.selector_entity_id')
        ->and($forEmployees)->toContain(Skill::class.'.owner_employee_entity_id')
        ->and($forEmployees)->toContain(WorkforceEmployeeProjection::class.'.manager_entity_id');

    // Exact Position list: every real Position reference the merge rewrites.
    // When another Position column lands, extend this array. The probe test
    // below still uses Position because real rows in those columns are either
    // null (employee.position) or absent (no selector rows) in that fixture.
    $forPositions = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::Position));
    expect($forPositions)->toBe([
        WorkforceEmployeeProjection::class.'.position_entity_id',
        RequirementProfileSelector::class.'.selector_entity_id',
    ]);

    // Exact User list for the same reason the Position list used to be the
    // probe isolation contract: only employee.user_entity_id today. When a
    // real model declares another User column, EXTEND this array and move
    // the probes to a resource type nothing real declares; do not weaken
    // either exact list to toContain.
    $forUsers = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::User));
    expect($forUsers)->toBe([WorkforceEmployeeProjection::class.'.user_entity_id']);
});

/**
 * Bring a model into existence for one test: a file in a Models directory,
 * discovered by the same scan production uses.
 *
 * Cleanup cannot rest on `finally` alone — a fatal skips it. The three
 * measures below are not co-equal, and the one that stops the fatal is easy
 * to mistake for belt-and-braces (blb-people-connector#40):
 *
 *  - `class_exists($class, false)` before `require` is the entire defence
 *    against the sticky fatal. `->with(DomainModels::all())` resolves at
 *    collection time, which autoloads every file in the Models directory
 *    before any test body runs — so if a probe file was left behind, its
 *    class is already declared by the time this helper executes, and an
 *    unconditional `require` dies with "Cannot redeclare class" in every
 *    later run until a human deletes the file. Removing this guard passes
 *    review and turns the next leak into a wedge; it was measured, not
 *    reasoned: drop the guard, plant a leak, fatal.
 *  - unlink-before-write does not protect against anything on its own:
 *    `file_put_contents` already truncates, and when the guard skips the
 *    `require` the fresh source is never loaded either way. Measured, both
 *    ways, in the review of #52; it stays because writing over a file you
 *    did not remove is the habit that produces the next leak.
 *  - the `.gitignore` entry protects against a leak reaching a commit.
 *
 * With all three in place a leaked file makes the run red but recoverable,
 * and the run cleans it up.
 *
 * @return array{string, class-string}
 */
function workforceReferenceProbeModel(string $name, string $source): array
{
    $path = dirname(__DIR__, 2).'/Models/'.$name.'.php';
    $class = 'App\\Domains\\PeopleConnector\\Connector\\Models\\'.$name;

    if (file_exists($path)) {
        unlink($path);
    }

    file_put_contents($path, $source);

    if (! class_exists($class, false)) {
        require $path;
    }

    DomainModels::forget();

    return [$path, $class];
}

function workforceReferenceProbeCleanup(string $path): void
{
    if (file_exists($path)) {
        unlink($path);
    }

    DomainModels::forget();
}

test('a model that declares a reference joins the merge without any list being edited', function (): void {
    // Two probes, one company-owned and one not, both declaring an existing
    // column under a resource type no real model declares it for — so when
    // that kind of entity is merged, the only thing that can move the row
    // is the probe. The claim is about a model that does not exist yet, so
    // the test brings it into existence.
    [$ownedPath, $owned] = workforceReferenceProbeModel('ZzContractProbeOwned', <<<'PHP'
    <?php

    namespace App\Domains\PeopleConnector\Connector\Models;

    use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
    use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
    use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
    use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;

    final class ZzContractProbeOwned extends TenantOwnedModel implements ReferencesWorkforceEntities
    {
        use CompanyOwned;

        protected $table = 'people_connector_skill_skills';

        public function workforceReferences(): array
        {
            return [new WorkforceReference('owner_employee_entity_id', WorkforceResourceType::Position)];
        }
    }
    PHP);
    [$plainPath, $plain] = workforceReferenceProbeModel('ZzContractProbePlain', <<<'PHP'
    <?php

    namespace App\Domains\PeopleConnector\Connector\Models;

    use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
    use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
    use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

    final class ZzContractProbePlain extends TenantOwnedModel implements ReferencesWorkforceEntities
    {
        protected $table = 'people_connector_connector_workforce_employees';

        public function workforceReferences(): array
        {
            return [new WorkforceReference('manager_entity_id', WorkforceResourceType::Position)];
        }
    }
    PHP);

    try {
        $forPositions = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::Position));
        expect(DomainModels::all())->toContain($owned, $plain)
            ->and($forPositions)->toContain($owned.'.owner_employee_entity_id', $plain.'.manager_entity_id');

        // Now the layer the registry check stops short of: a real merge.
        [$tenant] = createTenantWithCompany(['name' => 'Probe Merge Tenant']);
        app(TenantContext::class)->set((int) $tenant->id);
        $connections = app(ProviderConnectionStore::class);
        $connection = $connections->activate((int) $connections->configure(ProviderScope::tenant(), 'test.people')->id);
        $projections = app(WorkforceProjectionStore::class);
        $identities = app(WorkforceIdentityStore::class);
        $at = new DateTimeImmutable('2026-08-30T09:00:00+00:00');
        $company = new ExternalReference('test.people', WorkforceResourceType::Company, 'PROBE-CO');
        $position = fn (string $id): ExternalReference => new ExternalReference('test.people', WorkforceResourceType::Position, $id);
        $projections->upsert((int) $connection->id, new WorkforceCompany($company, 'Probe Co', true, $at));
        $projections->upsert((int) $connection->id, new WorkforceEmployee(new ExternalReference('test.people', WorkforceResourceType::Employee, 'PROBE-EMP'), $company, 'Probe Employee', true, $at, $at));
        foreach (['POS-OLD', 'POS-NEW'] as $id) {
            $projections->upsert((int) $connection->id, new WorkforcePosition($position($id), $company, $id, true, $at, $at));
        }
        $companyId = (int) $identities->resolve((int) $connection->id, $company)->id;
        $oldPosition = (int) $identities->resolve((int) $connection->id, $position('POS-OLD'))->id;
        $catalog = app(SkillCatalogStore::class);
        $category = $catalog->defineCategory($companyId, 'probe', 'Probe');
        $skill = $catalog->defineSkill($companyId, new SkillDraft(
            code: 'probe.skill', name: 'Probe', definition: 'Probe.', categoryId: (int) $category->id, scope: SkillScope::Shared,
            criticalClassification: null, evidenceGuide: null, defaultAssessmentMethod: AssessmentMethod::DirectObservation, defaultReassessmentMonths: 12,
        ));
        // Point the probe-declared columns at the position entity directly:
        // no store would, which is the point — only the probe declares them
        // as position references.
        DB::table('people_connector_skill_skills')->where('id', $skill->id)->update(['owner_employee_entity_id' => $oldPosition]);
        DB::table('people_connector_connector_workforce_employees')->where('tenant_id', $tenant->id)->update(['manager_entity_id' => $oldPosition]);

        $identities->merge((int) $connection->id, $position('POS-OLD'), $position('POS-NEW'), $at->modify('+1 hour'), new WorkforceProvenance('identity_merge', 'probe-review'));
        $newPosition = (int) $identities->resolve((int) $connection->id, $position('POS-NEW'))->id;

        expect($newPosition)->not->toBe($oldPosition)
            ->and((int) DB::table('people_connector_skill_skills')->where('id', $skill->id)->value('owner_employee_entity_id'))->toBe($newPosition)
            ->and((int) DB::table('people_connector_connector_workforce_employees')->where('tenant_id', $tenant->id)->value('manager_entity_id'))->toBe($newPosition);
    } finally {
        workforceReferenceProbeCleanup($ownedPath);
        workforceReferenceProbeCleanup($plainPath);
        app(TenantContext::class)->clear();
    }

    expect(DomainModels::all())->not->toContain($owned, $plain);
});
