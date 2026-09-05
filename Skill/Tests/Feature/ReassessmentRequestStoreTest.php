<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Data\DevelopmentActionDraft;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionType;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestSource;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestStatus;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\SkillCoverageState;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\ReassessmentRequest;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\AssessmentStore;
use App\Domains\PeopleConnector\Skill\Services\DevelopmentActionStore;
use App\Domains\PeopleConnector\Skill\Services\ReassessmentRequestStore;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use App\Domains\PeopleConnector\Skill\Services\SkillScoreHistory;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Data\TrainingEventDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use App\Domains\PeopleConnector\Training\Services\TrainingEventStore;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

final class ReassessmentFixtureRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

function reassessmentWorkflowAudience(): void
{
    app()->instance(SkillAudience::class, new class extends SkillAudience
    {
        public function __construct() {}

        public function authorizeAssessmentSubmission(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeHodVerification(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeAssessmentFinalization(User $user, int $companyEntityId, int $employeeEntityId): void {}
    });
}

function reassessmentActor(int $id): User
{
    return User::factory()->make(['id' => $id]);
}

function finalizeReassessmentAssessment(int $companyEntityId, AssessmentDraft $draft, int $hodVerifierUserId = 10): SkillAssessment
{
    $store = app(AssessmentStore::class);
    $assessorId = $draft->assessorUserId ?? 9;
    $submitted = $store->submit(reassessmentActor($assessorId), $companyEntityId, $draft);
    $pending = $store->requestHodVerification(reassessmentActor($assessorId), $companyEntityId, (int) $submitted->id);
    $store->verifyHod(reassessmentActor($hodVerifierUserId), $companyEntityId, (int) $pending->id, 'Verified.');

    return $store->finalizeVerified(reassessmentActor($hodVerifierUserId), $companyEntityId, (int) $pending->id);
}

/**
 * @return array{int, int, int, int, int} tenant, company, employee, skill, ownerEmployee
 */
function reassessmentFixture(): array
{
    [$tenant, $platformCompany] = createTenantWithCompany(
        ['name' => 'Reassessment Tenant'],
        ['name' => 'Reassessment Platform Company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;

    $company = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => 'company',
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId,
        'company_id' => $platformCompany->id,
        'scope_key' => 'company:'.$platformCompany->id,
        'provider_id' => 'test.people',
        'status' => 'active',
    ]);
    $organization = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId, 'resource_type' => 'organization_unit', 'state' => 'active', 'first_seen_at' => now(),
    ]);
    $organizationIdentity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $organization->id,
        'provider_id' => 'test.people', 'resource_type' => 'organization_unit', 'external_id' => 'org-'.$organization->id,
        'external_id_hash' => hash('sha256', 'org-'.$organization->id), 'state' => 'active',
        'effective_from' => now(), 'last_observed_at' => now(),
    ]);
    WorkforceOrganizationUnitProjection::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $company->id,
        'workforce_entity_id' => $organization->id, 'source_identity_id' => $organizationIdentity->id,
        'name' => 'Operations', 'active' => true, 'effective_at' => now(), 'observed_at' => now(),
    ]);
    $position = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId, 'resource_type' => 'position', 'state' => 'active', 'first_seen_at' => now(),
    ]);
    $positionIdentity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $position->id,
        'provider_id' => 'test.people', 'resource_type' => 'position', 'external_id' => 'pos-'.$position->id,
        'external_id_hash' => hash('sha256', 'pos-'.$position->id), 'state' => 'active',
        'effective_from' => now(), 'last_observed_at' => now(),
    ]);
    WorkforcePositionProjection::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $company->id,
        'workforce_entity_id' => $position->id, 'source_identity_id' => $positionIdentity->id,
        'organization_entity_id' => $organization->id, 'name' => 'Operator',
        'active' => true, 'effective_at' => now(), 'observed_at' => now(),
    ]);

    $employeeIds = [];
    foreach (['employee', 'owner'] as $label) {
        $entity = WorkforceEntity::query()->create([
            'tenant_id' => $tenantId,
            'resource_type' => 'employee',
            'state' => WorkforceEntity::STATE_ACTIVE,
            'first_seen_at' => now(),
        ]);
        $identity = ExternalIdentity::query()->create([
            'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $entity->id,
            'provider_id' => 'test.people', 'resource_type' => 'employee', 'external_id' => $label.'-'.$entity->id,
            'external_id_hash' => hash('sha256', $label.'-'.$entity->id), 'state' => 'active',
            'effective_from' => now(), 'last_observed_at' => now(),
        ]);
        WorkforceEmployeeProjection::query()->create([
            'tenant_id' => $tenantId, 'company_entity_id' => $company->id, 'workforce_entity_id' => $entity->id,
            'source_identity_id' => $identity->id, 'display_name' => $label, 'employee_number' => $label,
            'organization_entity_id' => $organization->id, 'position_entity_id' => $position->id,
            'active' => true, 'effective_at' => now(), 'observed_at' => now(),
        ]);
        $employeeIds[] = (int) $entity->id;
    }
    [$employeeId, $ownerId] = $employeeIds;

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
        defaultReassessmentMonths: 6,
    ));
    app(SkillCatalogDefaults::class)->install((int) $company->id);

    app()->instance(ResolvesSkillRequirements::class, new ReassessmentFixtureRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 1,
            skillId: (int) $skill->id,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
    ]));

    reassessmentWorkflowAudience();

    return [$tenantId, (int) $company->id, $employeeId, (int) $skill->id, $ownerId];
}

test('completing a development intervention opens a reassessment request without changing the score', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId, $ownerId] = reassessmentFixture();

    $baseline = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Baseline,
        assessedAt: now()->subMonths(2),
        evidence: 'Baseline observation.',
        assessorUserId: 9,
    ));

    $actions = app(DevelopmentActionStore::class)->proposeFromAssessments(
        $companyEntityId,
        [(int) $baseline->id],
        new DevelopmentActionDraft(
            employeeEntityId: $employeeEntityId,
            type: DevelopmentActionType::Coaching,
            objective: 'Reach level 4',
            intervention: 'Supervised practice',
            expectedEvidence: 'Observed competent lift',
            ownerEmployeeEntityId: $ownerId,
            hrCoordinatorEmployeeEntityId: $ownerId,
            startDate: today(),
            dueDate: today()->addWeeks(2),
            trainerEmployeeEntityId: $ownerId,
        ),
        actorUserId: 9,
    );
    $action = $actions[0];
    app(DevelopmentActionStore::class)->approve($companyEntityId, (int) $action->id, actorUserId: 9);

    $beforeScore = EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->first();

    $action = app(DevelopmentActionStore::class)->completeIntervention(
        $companyEntityId,
        (int) $action->id,
        evidence: 'Training attendance signed.',
        reassessmentDue: today()->addDays(14),
        actorUserId: 9,
        assignedEvaluatorUserId: 11,
    );

    expect($action->status)->toBe(DevelopmentActionStatus::PendingReassessment);

    $request = ReassessmentRequest::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('source_development_action_id', $action->id)
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ReassessmentRequestStatus::Open)
        ->and($request->assigned_evaluator_user_id)->toBe(11)
        ->and($request->cycle)->toBe(AssessmentCycle::PostTraining);

    $afterScore = EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->first();

    expect($afterScore?->current_level)->toBe($beforeScore?->current_level)
        ->and($afterScore?->source_assessment_id)->toBe($beforeScore?->source_assessment_id);
});

test('expired certificate validity clears current coverage while history remains', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = reassessmentFixture();

    $expired = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 4,
        method: AssessmentMethod::Certification,
        cycle: AssessmentCycle::Recertification,
        assessedAt: now()->subYear(),
        evidence: 'Licence copy on file.',
        certificateNumber: 'LIC-1',
        validUntil: today()->subDay(),
        assessorUserId: 9,
    ));

    expect(EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->exists())->toBeFalse();

    expect($expired->refresh()->status)->toBe(AssessmentStatus::Finalized);

    $fresh = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 3,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now()->subDays(3),
        evidence: 'Reobserved after expiry.',
        validUntil: today()->addMonths(6),
        assessorUserId: 9,
    ));

    $score = EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->first();

    expect($score)->not->toBeNull()
        ->and($score->source_assessment_id)->toBe($fresh->id)
        ->and($score->current_level)->toBe(3)
        ->and($score->coverageState())->toBe(SkillCoverageState::Current);
});

test('linking a post-training assessment fulfills the open reassessment request', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId, $ownerId] = reassessmentFixture();

    $baseline = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Baseline,
        assessedAt: now()->subMonths(1),
        evidence: 'Baseline.',
        assessorUserId: 9,
    ));

    $actions = app(DevelopmentActionStore::class)->proposeFromAssessments(
        $companyEntityId,
        [(int) $baseline->id],
        new DevelopmentActionDraft(
            employeeEntityId: $employeeEntityId,
            type: DevelopmentActionType::Coaching,
            objective: 'Reach level 4',
            intervention: 'Coaching',
            expectedEvidence: 'Observed',
            ownerEmployeeEntityId: $ownerId,
            hrCoordinatorEmployeeEntityId: $ownerId,
            startDate: today(),
            dueDate: today()->addWeeks(2),
            trainerEmployeeEntityId: $ownerId,
        ),
        actorUserId: 9,
    );
    $action = app(DevelopmentActionStore::class)->approve($companyEntityId, (int) $actions[0]->id, actorUserId: 9);
    $action = app(DevelopmentActionStore::class)->completeIntervention(
        $companyEntityId,
        (int) $action->id,
        evidence: 'Done',
        reassessmentDue: today()->addDays(7),
        actorUserId: 9,
    );

    $post = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 4,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::PostTraining,
        assessedAt: now(),
        evidence: 'Post-training observation.',
        assessorUserId: 12,
    ));

    $action = app(DevelopmentActionStore::class)->linkReassessment(
        $companyEntityId,
        (int) $action->id,
        (int) $post->id,
        actorUserId: 9,
    );

    $request = app(ReassessmentRequestStore::class)->openQuery($companyEntityId)
        ->where('source_development_action_id', $action->id)
        ->first();

    expect($request)->toBeNull();

    $fulfilled = ReassessmentRequest::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('source_development_action_id', $action->id)
        ->first();

    expect($fulfilled?->status)->toBe(ReassessmentRequestStatus::Fulfilled)
        ->and($fulfilled?->fulfilled_assessment_id)->toBe($post->id)
        ->and($fulfilled?->before_level)->toBe(2)
        ->and($fulfilled?->achieved)->toBeTrue()
        ->and($action->status)->toBe(DevelopmentActionStatus::Completed);
});

test('verified training participation opens reassessment without changing the score', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId, $ownerId] = reassessmentFixture();

    finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Baseline,
        assessedAt: now()->subMonths(1),
        evidence: 'Baseline.',
        assessorUserId: 9,
    ));

    $before = EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->first();

    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, new TrainingCourseDraft(
        code: 'forklift.refresh',
        title: 'Forklift refresh',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [$skillId],
        internalTrainerEmployeeEntityId: $ownerId,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyEntityId, new TrainingEventDraft(
        courseId: (int) $course->id,
        startsAt: now()->addDays(2)->setTime(9, 0),
        endsAt: now()->addDays(2)->setTime(17, 0),
        capacity: 8,
        organizerEmployeeEntityId: $ownerId,
        internalTrainerEmployeeEntityId: $ownerId,
    ), actorUserId: 9);

    $request = app(ReassessmentRequestStore::class)->requestFromTrainingEvent(
        $companyEntityId,
        (int) $event->id,
        $employeeEntityId,
        $skillId,
        targetLevel: 4,
        dueDate: today()->addDays(14),
        assignedEvaluatorUserId: 11,
        createdByUserId: 9,
    );

    expect($request->source)->toBe(ReassessmentRequestSource::TrainingEvent)
        ->and($request->source_training_event_id)->toBe($event->id)
        ->and($request->before_level)->toBe(2)
        ->and($request->status)->toBe(ReassessmentRequestStatus::Open);

    $again = app(ReassessmentRequestStore::class)->requestFromTrainingEvent(
        $companyEntityId,
        (int) $event->id,
        $employeeEntityId,
        $skillId,
        targetLevel: 4,
        dueDate: today()->addDays(14),
    );
    expect($again->id)->toBe($request->id);

    $after = EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->first();

    expect($after?->current_level)->toBe($before?->current_level)
        ->and($after?->source_assessment_id)->toBe($before?->source_assessment_id);
});

test('expired coverage opens renewal work and score history stays append-only', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = reassessmentFixture();

    $expired = finalizeReassessmentAssessment($companyEntityId, new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 4,
        method: AssessmentMethod::Certification,
        cycle: AssessmentCycle::Recertification,
        assessedAt: now()->subYear(),
        evidence: 'Licence copy on file.',
        certificateNumber: 'LIC-9',
        validUntil: today()->subDay(),
        assessorUserId: 9,
    ));

    expect(EmployeeSkillScore::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->exists())->toBeFalse();

    $opened = app(ReassessmentRequestStore::class)->openRenewalsForExpiredCoverage(
        $companyEntityId,
        today()->addDays(30),
    );

    expect($opened)->toBe(1);

    $request = ReassessmentRequest::query()
        ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
        ->where('source_assessment_id', $expired->id)
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->source)->toBe(ReassessmentRequestSource::CertificationExpiry)
        ->and($request->cycle)->toBe(AssessmentCycle::Recertification)
        ->and($request->before_level)->toBeNull();

    expect(app(ReassessmentRequestStore::class)->openRenewalsForExpiredCoverage(
        $companyEntityId,
        today()->addDays(30),
    ))->toBe(0);

    $history = app(SkillScoreHistory::class)->timeline($companyEntityId, $employeeEntityId, $skillId);
    $kinds = array_column($history, 'kind');

    expect($kinds)->toContain('assessment')
        ->and($kinds)->toContain('reassessment_request')
        ->and(app(SkillScoreHistory::class)->assessments($companyEntityId, $employeeEntityId, $skillId))->toHaveCount(1)
        ->and(EmployeeSkillScore::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->where('employee_entity_id', $employeeEntityId)
            ->where('skill_id', $skillId)
            ->exists())->toBeFalse();
});
