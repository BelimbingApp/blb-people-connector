<?php

use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Services\RequirementResolver;
use DateTimeInterface;

/**
 * Fixture-only resolver — unit-tests assessment gap math against the contract
 * DTO with no profile implementation loaded (BelimbingApp/blb-people#80).
 *
 * This is NOT the architectural boundary guard; see the arch expectations below
 * (BelimbingApp/blb-people-connector#83).
 */
final class FixtureSkillRequirements implements ResolvesSkillRequirements
{
    /**
     * @param  list<ResolvedSkillRequirement>  $rows
     */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

test('assessment gap math runs against fixture requirements with no profile implementation', function (): void {
    $resolver = new FixtureSkillRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 3,
            skillId: 101,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 3,
            skillId: 202,
            requiredLevel: 2,
            criticality: RequirementCriticality::Development,
        ),
    ]);

    app()->instance(ResolvesSkillRequirements::class, $resolver);

    $requirements = app(ResolvesSkillRequirements::class)->requirementsFor([
        'company_entity_id' => 1,
    ]);

    expect($requirements)->toHaveCount(2)
        ->and($requirements[0]->gap(2))->toBe(2)
        ->and($requirements[0]->gap(4))->toBe(0)
        ->and($requirements[0]->gap(5))->toBe(0)
        ->and($requirements[1]->gap(0))->toBe(2)
        ->and($requirements[0]->requirementReference)->toBe('fixture.ops')
        ->and($requirements[0]->requirementVersion)->toBe(3)
        ->and($requirements[0]->mandatoryGate)->toBeTrue()
        ->and(class_exists(RequirementResolver::class))->toBeTrue();

    expect($resolver)->toBeInstanceOf(ResolvesSkillRequirements::class)
        ->and($resolver)->not->toBeInstanceOf(RequirementResolver::class);
});

/**
 * Load-bearing boundary guard (blb-people-connector#83).
 *
 * Assessment must only see ResolvesSkillRequirements + ResolvedSkillRequirement.
 * Pest's not->toUse(class-string) can silently pass; not->toBeUsedIn is the
 * direction that actually fails when a profile type is imported into the
 * assessment surface.
 *
 * Profile internals under test:
 * - RequirementProfile / RequirementProfileSelector / RequirementItem models
 * - RequirementProfileStore / RequirementResolver (concrete profile machinery)
 *
 * Assessment surface under test (FQCNs — files may land via people #12 / #80):
 * - AssessmentStore
 * - Livewire\Assessment (HOD matrix)
 * - SkillAssessment / EmployeeSkillScore models
 */
$profileInternals = [
    'App\Domains\PeopleConnector\Skill\Models\RequirementProfile',
    'App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector',
    'App\Domains\PeopleConnector\Skill\Models\RequirementItem',
    'App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore',
    'App\Domains\PeopleConnector\Skill\Services\RequirementResolver',
];

$assessmentSurface = [
    'App\Domains\PeopleConnector\Skill\Services\AssessmentStore',
    'App\Domains\PeopleConnector\Skill\Livewire\Assessment',
    'App\Domains\PeopleConnector\Skill\Models\SkillAssessment',
    'App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore',
];

arch('assessment surface must not import requirement-profile internals')
    ->expect($profileInternals)
    ->not->toBeUsedIn($assessmentSurface);

arch('requirement-profile models stay on the profile side of the seam')
    ->expect([
        'App\Domains\PeopleConnector\Skill\Models\RequirementProfile',
        'App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector',
        'App\Domains\PeopleConnector\Skill\Models\RequirementItem',
    ])
    ->toOnlyBeUsedIn([
        'App\Domains\PeopleConnector\Skill\Models',
        'App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore',
        'App\Domains\PeopleConnector\Skill\Services\RequirementResolver',
        'App\Domains\PeopleConnector\Skill\Data',
        'App\Domains\PeopleConnector\Skill\Database',
        'App\Domains\PeopleConnector\Skill\Enums',
        'App\Domains\PeopleConnector\Skill\Events',
        'App\Domains\PeopleConnector\Skill\Exceptions',
        'App\Domains\PeopleConnector\Skill\Tests',
    ]);

/**
 * Arch rules catch use/import edges; they do not see raw table-name strings.
 * Scan assessment-surface PHP sources for the profile tables so a
 * DB::table('people_connector_skill_requirement_…') breach also goes red.
 */
test('assessment surface php sources never name requirement-profile tables', function (): void {
    $skillRoot = dirname(__DIR__, 2);
    $relativePaths = [
        'Services/AssessmentStore.php',
        'Livewire/Assessment',
        'Models/SkillAssessment.php',
        'Models/EmployeeSkillScore.php',
    ];

    $forbidden = [
        'people_connector_skill_requirement_profiles',
        'people_connector_skill_requirement_profile_selectors',
        'people_connector_skill_requirement_items',
    ];

    $files = [];
    foreach ($relativePaths as $relative) {
        $absolute = $skillRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            $files[] = $absolute;

            continue;
        }
        if (is_dir($absolute)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.php')) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
    }

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        foreach ($forbidden as $table) {
            expect($contents)
                ->not->toContain($table, "assessment surface must not name profile table [{$table}] in {$file}");
        }
    }
});
