<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Skill\Data\DevelopmentActionDraft;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionClosure;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionType;
use App\Domains\PeopleConnector\Skill\Enums\HodVerification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidDevelopmentActionException;
use App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentAction;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentActionAuditEvent;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\DevelopmentActionPriority;
use App\Domains\PeopleConnector\Skill\Services\DevelopmentActionStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenant: int, platform_company: int, company: int, employees: list<int>, skill: int} */
function developmentActionFixture(int $employeeCount = 4): array
{
    [$tenant, $platformCompany] = createTenantWithCompany(
        ['name' => 'Development Action Tenant'],
        ['name' => 'Development Action Platform Company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;
    $company = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId, 'resource_type' => 'company', 'state' => 'active', 'first_seen_at' => now(),
    ]);
    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId, 'company_id' => $platformCompany->id,
        'scope_key' => 'company:'.$platformCompany->id, 'provider_id' => 'test.people', 'status' => 'active',
    ]);
    $companyIdentity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $company->id,
        'provider_id' => 'test.people', 'resource_type' => 'company', 'external_id' => 'company-'.$company->id,
        'external_id_hash' => hash('sha256', 'company-'.$company->id), 'state' => 'active',
        'effective_from' => now(), 'last_observed_at' => now(),
    ]);
    WorkforceCompanyProjection::query()->create([
        'tenant_id' => $tenantId, 'workforce_entity_id' => $company->id,
        'source_identity_id' => $companyIdentity->id, 'name' => 'Development Workforce Company',
        'active' => true, 'effective_at' => now(), 'observed_at' => now(),
    ]);
    $organization = WorkforceEntity::query()->create([
        'tenant_id' => $tenantId, 'resource_type' => 'organization_unit', 'state' => 'active', 'first_seen_at' => now(),
    ]);
    $organizationIdentity = ExternalIdentity::query()->create([
        'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $organization->id,
        'provider_id' => 'test.people', 'resource_type' => 'organization_unit', 'external_id' => 'organization-'.$organization->id,
        'external_id_hash' => hash('sha256', 'organization-'.$organization->id), 'state' => 'active',
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
        'provider_id' => 'test.people', 'resource_type' => 'position', 'external_id' => 'position-'.$position->id,
        'external_id_hash' => hash('sha256', 'position-'.$position->id), 'state' => 'active',
        'effective_from' => now(), 'last_observed_at' => now(),
    ]);
    WorkforcePositionProjection::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $company->id,
        'workforce_entity_id' => $position->id, 'source_identity_id' => $positionIdentity->id,
        'organization_entity_id' => $organization->id, 'name' => 'Permit Technician',
        'active' => true, 'effective_at' => now(), 'observed_at' => now(),
    ]);
    $employees = [];
    foreach (range(1, $employeeCount) as $index) {
        $entity = WorkforceEntity::query()->create([
            'tenant_id' => $tenantId, 'resource_type' => 'employee', 'state' => 'active', 'first_seen_at' => now(),
        ]);
        $identity = ExternalIdentity::query()->create([
            'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'workforce_entity_id' => $entity->id,
            'provider_id' => 'test.people', 'resource_type' => 'employee', 'external_id' => 'employee-'.$entity->id,
            'external_id_hash' => hash('sha256', 'employee-'.$entity->id), 'state' => 'active',
            'effective_from' => now(), 'last_observed_at' => now(),
        ]);
        WorkforceEmployeeProjection::query()->create([
            'tenant_id' => $tenantId, 'company_entity_id' => $company->id, 'workforce_entity_id' => $entity->id,
            'source_identity_id' => $identity->id, 'display_name' => "Person {$index}", 'employee_number' => "P{$index}",
            'organization_entity_id' => $organization->id, 'position_entity_id' => $position->id,
            'active' => true, 'effective_at' => now(), 'observed_at' => now(),
        ]);
        $employees[] = (int) $entity->id;
    }

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'safety.permit', name: 'Safety Permit', definition: 'Works safely under permit.',
        categoryId: (int) $category->id, scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed permit execution.', defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));

    return ['tenant' => $tenantId, 'platform_company' => (int) $platformCompany->id,
        'company' => (int) $company->id, 'employees' => $employees, 'skill' => (int) $skill->id];
}

function developmentAssessment(array $fixture, int $employeeId, int $level = 1, ?int $gap = null, ?DateTimeInterface $assessedAt = null, AssessmentCycle $cycle = AssessmentCycle::Annual): SkillAssessment
{
    $required = 4;
    $gap ??= max($required - $level, 0);

    $assessedAt ??= now()->subDay();

    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $fixture['tenant'], 'company_entity_id' => $fixture['company'],
        'employee_entity_id' => $employeeId, 'skill_id' => $fixture['skill'],
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2, 'required_level' => $required,
        'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $level, 'gap' => $gap,
        'weighted_gap' => $gap * 100, 'priority_score' => $gap * 300,
        'result_band' => AssessmentResultBand::fromGap($gap, $level, $required),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => $cycle,
        'status' => AssessmentStatus::Finalized, 'evidence' => 'Observed work sample.', 'assessed_at' => $assessedAt,
        'hod_verification' => HodVerification::Verified, 'finalized_at' => $assessedAt, 'finalized_by_user_id' => 10,
    ]);
    EmployeeSkillScore::query()->forCompany($fixture['tenant'], $fixture['company'])->updateOrCreate([
        'tenant_id' => $fixture['tenant'], 'employee_entity_id' => $employeeId, 'skill_id' => $fixture['skill'],
    ], [
        'company_entity_id' => $fixture['company'], 'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2, 'required_level' => $required,
        'current_level' => $level, 'gap' => $gap, 'mandatory_gate' => true,
        'criticality' => RequirementCriticality::Critical, 'assessed_at' => $assessedAt,
    ]);

    return $assessment;
}

function developmentDraft(array $fixture, int $employeeId, array $overrides = []): DevelopmentActionDraft
{
    return new DevelopmentActionDraft(...array_merge([
        'employeeEntityId' => $employeeId,
        'type' => DevelopmentActionType::Coaching,
        'objective' => 'Reach permit level four safely.',
        'intervention' => 'Four supervised permit cycles with feedback.',
        'expectedEvidence' => 'Signed observation checklist for four cycles.',
        'ownerEmployeeEntityId' => $fixture['employees'][1],
        'hrCoordinatorEmployeeEntityId' => $fixture['employees'][2],
        'startDate' => now(), 'dueDate' => now()->addDays(10),
        'trainerEmployeeEntityId' => $fixture['employees'][3],
    ], $overrides));
}

test('priority is visible and mandatory gates sort independently', function (): void {
    $priority = app(DevelopmentActionPriority::class);

    expect($priority->score(3, RequirementCriticality::Critical))->toBe(9)
        ->and($priority->score(3, RequirementCriticality::Development))->toBe(3)
        ->and($priority->explanation(3, RequirementCriticality::Critical, true))
        ->toContain('Mandatory gate: yes', 'Score 9 = gap 3 × Critical multiplier 3');
});

test('bulk proposals are atomic, named, due, deduplicated, and fully audited', function (): void {
    $fixture = developmentActionFixture(5);
    $first = developmentAssessment($fixture, $fixture['employees'][0]);
    $second = developmentAssessment($fixture, $fixture['employees'][4], 2);
    $store = app(DevelopmentActionStore::class);

    $actions = $store->proposeFromAssessments($fixture['company'], [$first->id, $second->id],
        developmentDraft($fixture, $fixture['employees'][0]), actorUserId: 10);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->owner_employee_entity_id)->toBe($fixture['employees'][1])
        ->and($actions[0]->hr_coordinator_employee_entity_id)->toBe($fixture['employees'][2])
        ->and($actions[0]->department_snapshot)->toBe('Operations')
        ->and($actions[0]->position_snapshot)->toBe('Permit Technician')
        ->and($actions[0]->due_date)->not->toBeNull()
        ->and($actions[0]->priority_score)->toBe(9)
        ->and($actions[0]->mandatory_gate)->toBeTrue()
        ->and(DevelopmentActionAuditEvent::query()->forCompany($fixture['tenant'], $fixture['company'])->count())->toBe(2);

    expect(fn () => $store->proposeFromAssessments($fixture['company'], [$first->id],
        developmentDraft($fixture, $fixture['employees'][0])))
        ->toThrow(InvalidDevelopmentActionException::class, 'already has a development action');

    expect(fn () => $store->proposeFromAssessments($fixture['company'], [$first->id, 999999],
        developmentDraft($fixture, $fixture['employees'][0])))
        ->toThrow(InvalidDevelopmentActionException::class, 'Every selected assessment')
        ->and(DevelopmentAction::query()->forCompany($fixture['tenant'], $fixture['company'])->count())->toBe(2);
});

test('ownership dates and trainers fail closed including sibling-company people', function (): void {
    $fixture = developmentActionFixture();
    $assessment = developmentAssessment($fixture, $fixture['employees'][0]);
    $store = app(DevelopmentActionStore::class);

    expect(fn () => $store->proposeFromAssessments($fixture['company'], [$assessment->id],
        developmentDraft($fixture, $fixture['employees'][0], ['dueDate' => now()->subDay()])))
        ->toThrow(InvalidDevelopmentActionException::class, 'Due date');

    expect(fn () => $store->proposeFromAssessments($fixture['company'], [$assessment->id],
        developmentDraft($fixture, $fixture['employees'][0], ['trainerEmployeeEntityId' => null])))
        ->toThrow(InvalidDevelopmentActionException::class, 'requires a trainer');

    $sibling = WorkforceEntity::query()->create([
        'tenant_id' => $fixture['tenant'], 'resource_type' => 'company', 'state' => 'active', 'first_seen_at' => now(),
    ]);
    expect(fn () => $store->proposeFromAssessments((int) $sibling->id, [$assessment->id],
        developmentDraft($fixture, $fixture['employees'][0])))
        ->toThrow(InvalidDevelopmentActionException::class, 'Every selected assessment');
});

test('manual actions require a durable justification and preserve the ownership rules', function (): void {
    $fixture = developmentActionFixture();
    $store = app(DevelopmentActionStore::class);
    $manual = developmentDraft($fixture, $fixture['employees'][0], [
        'skillId' => $fixture['skill'],
        'startingLevel' => 4,
        'targetLevel' => 5,
        'criticality' => RequirementCriticality::Development,
    ]);

    expect(fn () => $store->proposeManual($fixture['company'], $manual, 10))
        ->toThrow(InvalidDevelopmentActionException::class, 'justification');

    $created = $store->proposeManual($fixture['company'], developmentDraft($fixture, $fixture['employees'][0], [
        'skillId' => $fixture['skill'],
        'startingLevel' => 4,
        'targetLevel' => 5,
        'criticality' => RequirementCriticality::Development,
        'manualReason' => 'Prepare for a confirmed promotion into the permit-owner role.',
    ]), 10);

    expect($created->source_assessment_id)->toBeNull()
        ->and($created->manual_reason)->toContain('confirmed promotion')
        ->and($created->priority_score)->toBe(1);
});

test('completion waits for a later independent reassessment before competence closes', function (): void {
    $fixture = developmentActionFixture();
    $source = developmentAssessment($fixture, $fixture['employees'][0]);
    $store = app(DevelopmentActionStore::class);
    $action = $store->proposeFromAssessments($fixture['company'], [$source->id],
        developmentDraft($fixture, $fixture['employees'][0]), 10)[0];
    $action = $store->reviseProposal($fixture['company'], (int) $action->id,
        developmentDraft($fixture, $fixture['employees'][0], [
            'objective' => 'Tailored permit objective.',
            'skillId' => $fixture['skill'],
            'startingLevel' => 1,
            'targetLevel' => 4,
            'criticality' => RequirementCriticality::Critical,
            'mandatoryGate' => true,
        ]), 10);
    expect($action->objective)->toBe('Tailored permit objective.');
    $action = $store->approve($fixture['company'], (int) $action->id, 11);
    $action = $store->start($fixture['company'], (int) $action->id, 11);
    $action = $store->completeIntervention($fixture['company'], (int) $action->id,
        'Four signed observation sheets.', now()->addMonth(), 11);

    expect($action->status)->toBe(DevelopmentActionStatus::PendingReassessment)
        ->and($action->closure_status)->toBe(DevelopmentActionClosure::PendingReassessment)
        ->and($action->post_assessment_id)->toBeNull();

    $post = developmentAssessment($fixture, $fixture['employees'][0], 4, 0, now()->addMinute(), AssessmentCycle::PostTraining);
    $closed = $store->linkReassessment($fixture['company'], (int) $action->id, (int) $post->id, 12);

    expect($closed->status)->toBe(DevelopmentActionStatus::Completed)
        ->and($closed->closure_status)->toBe(DevelopmentActionClosure::ClosedCompetent)
        ->and($closed->improvement)->toBe(3)
        ->and(DevelopmentActionAuditEvent::query()->forCompany($fixture['tenant'], $fixture['company'])->where('development_action_id', $action->id)->count())->toBe(6);
});

test('owned actions remain operable during a provider outage and preserve an unsuccessful reassessment outcome', function (): void {
    $fixture = developmentActionFixture();
    $source = developmentAssessment($fixture, $fixture['employees'][0]);
    $store = app(DevelopmentActionStore::class);
    $action = $store->proposeFromAssessments($fixture['company'], [$source->id],
        developmentDraft($fixture, $fixture['employees'][0]), 10)[0];

    $connection = ProviderConnection::query()->where('tenant_id', $fixture['tenant'])->sole();
    $connection->update(['status' => ProviderConnection::STATUS_INACTIVE, 'deactivated_at' => now()]);

    $action = $store->approve($fixture['company'], (int) $action->id, 11);
    $action = $store->start($fixture['company'], (int) $action->id, 11);
    $action = $store->completeIntervention($fixture['company'], (int) $action->id,
        'Intervention evidence captured while the upstream provider was unavailable.', now()->addMonth(), 11);
    $post = developmentAssessment($fixture, $fixture['employees'][0], 2, 2, now()->addMinute(), AssessmentCycle::PostTraining);
    $closed = $store->linkReassessment($fixture['company'], (int) $action->id, (int) $post->id, 12);

    expect($closed->status)->toBe(DevelopmentActionStatus::Completed)
        ->and($closed->closure_status)->toBe(DevelopmentActionClosure::FurtherActionRequired)
        ->and($closed->improvement)->toBe(1)
        ->and($closed->completion_evidence)->toContain('upstream provider was unavailable');
});

test('cancellation closes with a reason and completed or cancelled work is never overdue', function (): void {
    $fixture = developmentActionFixture();
    $source = developmentAssessment($fixture, $fixture['employees'][0]);
    $store = app(DevelopmentActionStore::class);
    $action = $store->proposeFromAssessments($fixture['company'], [$source->id],
        developmentDraft($fixture, $fixture['employees'][0], ['startDate' => now()->subDays(5), 'dueDate' => now()->subDay()]), 10)[0];

    expect($action->daysOverdue())->toBe(1);
    $cancelled = $store->cancel($fixture['company'], (int) $action->id, 'Employee transferred.', 10);

    expect($cancelled->status)->toBe(DevelopmentActionStatus::Cancelled)
        ->and($cancelled->closure_status)->toBe(DevelopmentActionClosure::Cancelled)
        ->and($cancelled->daysOverdue())->toBe(0)
        ->and(DevelopmentActionAuditEvent::query()->forCompany($fixture['tenant'], $fixture['company'])->where('event_type', 'cancelled')->value('comment'))->toBe('Employee transferred.');

    $event = DevelopmentActionAuditEvent::query()->forCompany($fixture['tenant'], $fixture['company'])->where('event_type', 'cancelled')->sole();
    expect(fn () => $event->update(['comment' => 'rewritten']))
        ->toThrow(InvalidDevelopmentActionException::class, 'append-only')
        ->and(fn () => DB::table('people_connector_skill_development_action_events')->where('id', $event->id)->update(['comment' => 'raw rewrite']))
        ->toThrow(QueryException::class);
});

test('an authorized HOD or HR user can bulk-create selected gap proposals from the page', function (): void {
    $fixture = developmentActionFixture();
    $assessment = developmentAssessment($fixture, $fixture['employees'][0]);
    $user = User::factory()->create(['company_id' => $fixture['platform_company']]);
    foreach (['people-connector.skill.development-action.view', 'people-connector.skill.development-action.manage'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $fixture['platform_company'], 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id, 'capability_key' => $capability, 'is_allowed' => true,
        ]);
    }

    Livewire::actingAs($user)->test(DevelopmentActionIndex::class)
        ->set('selectedAssessmentIds', [(int) $assessment->id])
        ->set('objective', 'Reach permit level four safely.')
        ->set('intervention', 'Four coached permit cycles.')
        ->set('expectedEvidence', 'Signed observation checklist.')
        ->set('ownerEmployeeEntityId', $fixture['employees'][1])
        ->set('hrCoordinatorEmployeeEntityId', $fixture['employees'][2])
        ->set('trainerEmployeeEntityId', $fixture['employees'][3])
        ->call('propose')
        ->assertHasNoErrors('actions');

    $action = DevelopmentAction::query()->forCompany($fixture['tenant'], $fixture['company'])->sole();
    expect($action->source_assessment_id)->toBe($assessment->id)
        ->and($action->owner_employee_entity_id)->toBe($fixture['employees'][1]);

    $store = app(DevelopmentActionStore::class);
    $store->approve($fixture['company'], (int) $action->id, (int) $user->id);
    $store->start($fixture['company'], (int) $action->id, (int) $user->id);
    $store->completeIntervention($fixture['company'], (int) $action->id,
        'Signed observation checklist.', now()->addMonth(), (int) $user->id);
    $post = developmentAssessment($fixture, $fixture['employees'][0], 4, 0, now()->addMinute(), AssessmentCycle::PostTraining);

    Livewire::actingAs($user)->test(DevelopmentActionIndex::class)
        ->set("postAssessmentId.{$action->id}", (int) $post->id)
        ->call('verifyReassessment', (int) $action->id)
        ->assertHasNoErrors('actions');

    expect($action->refresh()->closure_status)->toBe(DevelopmentActionClosure::ClosedCompetent);
});
