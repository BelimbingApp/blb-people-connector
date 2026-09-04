<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\SkillAssessmentFinalized;
use App\Domains\PeopleConnector\Skill\Exceptions\FinalizedAssessmentImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\AssessmentStore;
use App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
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

    app(SkillCatalogDefaults::class)->install((int) $company->id);

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
        ->and($assessment->scale_id)->not->toBeNull()
        ->and($assessment->scale_version)->toBe(1)
        ->and($assessment->finalized_at)->not->toBeNull();

    $scale = ProficiencyScale::query()->forCompany($tenantId, $companyEntityId)->whereKey($assessment->scale_id)->sole();
    expect($scale->code)->toBe(SkillCatalogDefaults::SCALE_CODE)
        ->and($scale->version)->toBe($assessment->scale_version);

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

test('finalizeBatch is atomic: one bad cell rolls back the whole matrix save', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $good = assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3]);
    $bad = assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3, 'evidence' => '']);

    expect(fn () => $store->finalizeBatch($companyEntityId, [$good, $bad], finalizedByUserId: 1))
        ->toThrow(InvalidAssessmentException::class, 'Evidence');

    expect(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    $saved = $store->finalizeBatch($companyEntityId, [
        assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3]),
    ], finalizedByUserId: 1);

    expect($saved)->toHaveCount(1)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(1);
});

test('a back-dated finalize does not regress the current-score projection', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $newer = $store->finalize($companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 4,
        'assessedAt' => now()->subDays(1),
    ]), finalizedByUserId: 1);

    $older = $store->finalize($companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 1,
        'assessedAt' => now()->subDays(30),
    ]), finalizedByUserId: 2);

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(4)
        ->and($score->source_assessment_id)->toBe($newer->id)
        ->and($score->source_assessment_id)->not->toBe($older->id)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2);
});

test('finalize matches department-scoped requirements from the employee projection', function (): void {
    $tenant = createTenant(['name' => 'Scoped Assessment Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;

    $company = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    $dept = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'organization_unit',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    $employee = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'employee',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);

    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId,
        'scope_key' => 'tenant',
        'provider_id' => 'test.people',
        'status' => 'active',
    ]);
    $deptIdentity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $dept->id,
        'provider_id' => 'test.people',
        'resource_type' => 'organization_unit',
        'external_id' => 'dept-'.$dept->id,
        'external_id_hash' => hash('sha256', 'dept-'.$dept->id),
        'state' => 'active',
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);
    WorkforceOrganizationUnitProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $dept->id,
        'source_identity_id' => $deptIdentity->id,
        'company_entity_id' => $company->id,
        'name' => 'Ops Dept',
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);
    $identity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $employee->id,
        'provider_id' => 'test.people',
        'resource_type' => 'employee',
        'external_id' => 'emp-'.$employee->id,
        'external_id_hash' => hash('sha256', 'emp-'.$employee->id),
        'state' => 'active',
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);

    WorkforceEmployeeProjection::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $company->id,
        'workforce_entity_id' => $employee->id,
        'source_identity_id' => $identity->id,
        'display_name' => 'Scoped Worker',
        'organization_entity_id' => $dept->id,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'ops', 'Operations');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'dept.skill',
        name: 'Dept Skill',
        definition: 'Department scoped.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Quality,
        evidenceGuide: 'Evidence.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));
    app(SkillCatalogDefaults::class)->install((int) $company->id);

    $profiles = app(RequirementProfileStore::class);
    $draft = new RequirementProfileDraft(
        code: 'dept.scoped',
        name: 'Dept Scoped',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $dept->id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skill->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
    );
    $profile = $profiles->draft((int) $company->id, $draft);
    $profiles->publish((int) $company->id, (int) $profile->id);

    // Real resolver — no fixture bind — must see department from the projection.
    $assessment = app(AssessmentStore::class)->finalize(
        (int) $company->id,
        assessmentDraft((int) $employee->id, (int) $skill->id, ['assessedLevel' => 2]),
        finalizedByUserId: 1,
    );

    expect($assessment->requirement_reference)->toBe('dept.scoped')
        ->and($assessment->required_level)->toBe(3)
        ->and($assessment->gap)->toBe(1);
});
