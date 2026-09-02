<?php

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Skill\Models\Skill;
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
        ->and($forEmployees)->toContain(Skill::class.'.owner_employee_entity_id')
        ->and($forEmployees)->toContain(WorkforceEmployeeProjection::class.'.manager_entity_id');
});

test('a model that declares a reference joins the merge without any list being edited', function (): void {
    // The claim is about a model that does not exist yet, so the test brings
    // one into existence: a file in a Models directory, discovered by the
    // same scan production uses, and removed again whatever happens.
    $path = dirname(__DIR__, 2).'/Models/ZzContractProbeModel.php';
    $class = 'App\\Domains\\PeopleConnector\\Connector\\Models\\ZzContractProbeModel';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Domains\PeopleConnector\Connector\Models;

    use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
    use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
    use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

    final class ZzContractProbeModel extends TenantOwnedModel implements ReferencesWorkforceEntities
    {
        protected $table = 'people_connector_connector_workforce_employees';

        public function workforceReferences(): array
        {
            return [new WorkforceReference('position_entity_id', WorkforceResourceType::Position)];
        }
    }
    PHP);

    try {
        require $path;
        DomainModels::forget();

        $forPositions = array_map(fn (array $pair): string => $pair[0].'.'.$pair[1]->column, DomainModels::referencing(WorkforceResourceType::Position));

        expect(DomainModels::all())->toContain($class)
            ->and($forPositions)->toContain($class.'.position_entity_id');
    } finally {
        unlink($path);
        DomainModels::forget();
    }

    expect(DomainModels::all())->not->toContain($class);
});
