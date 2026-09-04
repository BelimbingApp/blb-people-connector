<?php

use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Services\RequirementResolver;
use DateTimeInterface;

/**
 * Fixture-only resolver — proves assessment gap math never needs profile
 * machinery (BelimbingApp/blb-people#80).
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

    // Bind only the contract — never RequirementResolver / profile models.
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

    // The consumer touched only the contract + DTO — the fixture class is the
    // stand-in for any future provider of requirements.
    expect($resolver)->toBeInstanceOf(ResolvesSkillRequirements::class)
        ->and($resolver)->not->toBeInstanceOf(RequirementResolver::class);
});
