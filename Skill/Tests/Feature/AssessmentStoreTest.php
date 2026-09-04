<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\SkillAssessmentFinalized;
use App\Domains\PeopleConnector\Skill\Exceptions\FinalizedAssessmentImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\AssessmentStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

final class AssessmentFixtureRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

/**
 * @return array{int, int, int, int} [tenantId, companyEntityId, employeeEntityId, skillId]
 */
function assessmentFixture(): array
{
    $tenant = createTenant(['name' => 'Assessment Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;

    $company = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    $employee = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'employee',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'ops', 'Operations');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed lift cycle.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));

    app()->instance(ResolvesSkillRequirements::class, new AssessmentFixtureRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 2,
            skillId: (int) $skill->id,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
    ]));

    return [$tenantId, (int) $company->id, (int) $employee->id, (int) $skill->id];
}

function assessmentDraft(int $employeeEntityId, int $skillId, array $overrides = []): AssessmentDraft
{
    $base = [
        'employeeEntityId' => $employeeEntityId,
        'skillId' => $skillId,
        'assessedLevel' => 2,
        'method' => AssessmentMethod::DirectObservation,
        'cycle' => AssessmentCycle::Annual,
        'assessedAt' => now(),
        'evidence' => 'Observed three compliant lift cycles with valid licence.',
        'notes' => null,
        'assessorUserId' => 9,
        'weightPercent' => 10.0,
    ];

    return new AssessmentDraft(...array_merge($base, $overrides));
}

test('finalize snapshots requirement and projects gap from the published contract', function (): void {
    Event::fake([SkillAssessmentFinalized::class]);
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();

    $assessment = app(AssessmentStore::class)->finalize(
        $companyEntityId,
        assessmentDraft($employeeEntityId, $skillId),
        finalizedByUserId: 9,
    );

    expect($assessment->status)->toBe(AssessmentStatus::Finalized)
        ->and($assessment->requirement_reference)->toBe('fixture.ops')
        ->and($assessment->requirement_version)->toBe(2)
        ->and($assessment->required_level)->toBe(4)
        ->and($assessment->assessed_level)->toBe(2)
        ->and($assessment->gap)->toBe(2)
        ->and((float) $assessment->weighted_gap)->toBe(20.0)
        ->and((float) $assessment->priority_score)->toBe(60.0)
        ->and($assessment->result_band)->toBe(AssessmentResultBand::MajorGap)
        ->and($assessment->mandatory_gate)->toBeTrue()
        ->and($assessment->finalized_at)->not->toBeNull();

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(2)
        ->and($score->gap)->toBe(2)
        ->and($score->source_assessment_id)->toBe($assessment->id);

    Event::assertDispatched(SkillAssessmentFinalized::class);
});

test('evidence is mandatory and scale values fail closed', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    expect(fn () => $store->finalize($companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'evidence' => '  ',
    ])))->toThrow(InvalidAssessmentException::class, 'Evidence');

    expect(fn () => $store->finalize($companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 9,
    ])))->toThrow(InvalidAssessmentException::class, '0 and 5');
});

test('finalized assessments are immutable; supersession keeps history and refreshes the score', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $first = $store->finalize($companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 1,
    ]), finalizedByUserId: 1);

    expect(fn () => $first->update(['notes' => 'tamper']))
        ->toThrow(FinalizedAssessmentImmutableException::class);

    expect(fn () => DB::transaction(fn () => SkillAssessment::query()
        ->forCompany($tenantId, $companyEntityId)
        ->whereKey($first->id)
        ->update(['notes' => 'raw tamper'])))
        ->toThrow(QueryException::class);

    $second = $store->finalize(
        $companyEntityId,
        assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 4]),
        supersedesAssessmentId: (int) $first->id,
        finalizedByUserId: 2,
    );

    expect($second->supersedes_assessment_id)->toBe($first->id)
        ->and($second->gap)->toBe(0)
        ->and($second->result_band)->toBe(AssessmentResultBand::Meets)
        ->and($first->refresh()->assessed_level)->toBe(1);

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(4)
        ->and($score->gap)->toBe(0)
        ->and($score->source_assessment_id)->toBe($second->id)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2);
});

test('a sibling company cannot finalize against this catalog or employee spine', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $sibling = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    expect(fn () => app(AssessmentStore::class)->finalize(
        (int) $sibling->id,
        assessmentDraft($employeeEntityId, $skillId),
    ))->toThrow(InvalidAssessmentException::class);
});
