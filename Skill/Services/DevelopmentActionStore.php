<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Skill\Data\DevelopmentActionDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionClosure;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestStatus;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidDevelopmentActionException;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentAction;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentActionAuditEvent;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\ReassessmentRequest;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Connector-owned source of truth for assessment-gap development actions. */
final class DevelopmentActionStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DevelopmentActionPriority $priority,
    ) {}

    /**
     * @param  list<int>  $assessmentIds
     * @return list<DevelopmentAction>
     */
    public function proposeFromAssessments(
        int $companyEntityId,
        array $assessmentIds,
        DevelopmentActionDraft $draft,
        ?int $actorUserId = null,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $assessmentIds)));
        if ($ids === []) {
            throw new InvalidDevelopmentActionException('Select at least one assessment gap.');
        }

        return DB::transaction(function () use ($companyEntityId, $ids, $draft, $actorUserId): array {
            $tenantId = $this->tenantContext->requireTenantId();
            $assessments = SkillAssessment::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($assessments->count() !== count($ids)) {
                throw new InvalidDevelopmentActionException('Every selected assessment must belong to this company.');
            }

            $created = [];
            foreach ($ids as $id) {
                /** @var SkillAssessment $assessment */
                $assessment = $assessments->get($id);
                $this->assertActionableAssessment($companyEntityId, $assessment);

                $sourceDraft = new DevelopmentActionDraft(
                    employeeEntityId: (int) $assessment->employee_entity_id,
                    type: $draft->type,
                    objective: $draft->objective,
                    intervention: $draft->intervention,
                    expectedEvidence: $draft->expectedEvidence,
                    ownerEmployeeEntityId: $draft->ownerEmployeeEntityId,
                    hrCoordinatorEmployeeEntityId: $draft->hrCoordinatorEmployeeEntityId,
                    startDate: $draft->startDate,
                    dueDate: $draft->dueDate,
                    trainerEmployeeEntityId: $draft->trainerEmployeeEntityId,
                    trainerProviderName: $draft->trainerProviderName,
                    skillId: (int) $assessment->skill_id,
                    startingLevel: (int) $assessment->assessed_level,
                    targetLevel: (int) $assessment->required_level,
                    criticality: $assessment->criticality,
                    mandatoryGate: (bool) $assessment->mandatory_gate,
                    nextSteps: $draft->nextSteps,
                    trainingCourseCode: $draft->trainingCourseCode,
                );
                $created[] = $this->create($companyEntityId, $sourceDraft, (int) $assessment->id, $actorUserId);
            }

            return $created;
        });
    }

    public function proposeManual(
        int $companyEntityId,
        DevelopmentActionDraft $draft,
        ?int $actorUserId = null,
    ): DevelopmentAction {
        if ($draft->skillId === null || $draft->startingLevel === null || $draft->targetLevel === null || $draft->criticality === null) {
            throw new InvalidDevelopmentActionException('Manual actions require a skill, starting level, target level, and criticality.');
        }
        if (trim((string) $draft->manualReason) === '') {
            throw new InvalidDevelopmentActionException('Manual actions require a recorded justification.');
        }

        return DB::transaction(fn (): DevelopmentAction => $this->create($companyEntityId, $draft, null, $actorUserId));
    }

    public function approve(int $companyEntityId, int $actionId, int $actorUserId): DevelopmentAction
    {
        return DB::transaction(function () use ($companyEntityId, $actionId, $actorUserId): DevelopmentAction {
            $action = $this->find($companyEntityId, $actionId, true);
            if ($action->status !== DevelopmentActionStatus::Proposed) {
                throw new InvalidDevelopmentActionException("Cannot approve {$action->status->label()} work.");
            }
            $to = $action->start_date->isFuture()
                ? DevelopmentActionStatus::Scheduled
                : DevelopmentActionStatus::NotStarted;
            $action->update(['status' => $to, 'approved_at' => now(), 'approved_by_user_id' => $actorUserId]);
            $this->record($action, 'approved', DevelopmentActionStatus::Proposed, $to, null, null, $actorUserId);

            return $action->refresh();
        });
    }

    public function reviseProposal(
        int $companyEntityId,
        int $actionId,
        DevelopmentActionDraft $draft,
        ?int $actorUserId = null,
    ): DevelopmentAction {
        return DB::transaction(function () use ($companyEntityId, $actionId, $draft, $actorUserId): DevelopmentAction {
            $action = $this->find($companyEntityId, $actionId, true);
            if ($action->status !== DevelopmentActionStatus::Proposed) {
                throw new InvalidDevelopmentActionException('Only a proposal can be tailored; approved work requires a lifecycle transition.');
            }
            if ($draft->employeeEntityId !== (int) $action->employee_entity_id
                || $draft->skillId !== (int) $action->skill_id
                || $draft->startingLevel !== (int) $action->starting_level
                || $draft->targetLevel !== (int) $action->target_level
                || $draft->criticality !== $action->criticality
                || $draft->mandatoryGate !== (bool) $action->mandatory_gate) {
                throw new InvalidDevelopmentActionException('Tailoring cannot rewrite the source gap snapshot.');
            }
            $this->validateDraft($companyEntityId, $draft);
            $action->update([
                'action_type' => $draft->type,
                'objective' => trim($draft->objective),
                'intervention' => trim($draft->intervention),
                'expected_evidence' => trim($draft->expectedEvidence),
                'owner_employee_entity_id' => $draft->ownerEmployeeEntityId,
                'hr_coordinator_employee_entity_id' => $draft->hrCoordinatorEmployeeEntityId,
                'trainer_employee_entity_id' => $draft->trainerEmployeeEntityId,
                'trainer_provider_name' => $draft->trainerProviderName === null ? null : trim($draft->trainerProviderName),
                'training_course_code' => $draft->trainingCourseCode,
                'start_date' => $draft->startDate,
                'due_date' => $draft->dueDate,
                'next_steps' => $draft->nextSteps,
            ]);
            $this->record($action, 'proposal_tailored', DevelopmentActionStatus::Proposed,
                DevelopmentActionStatus::Proposed, null, null, $actorUserId);

            return $action->refresh();
        });
    }

    public function start(int $companyEntityId, int $actionId, ?int $actorUserId = null): DevelopmentAction
    {
        return $this->transition($companyEntityId, $actionId,
            [DevelopmentActionStatus::NotStarted, DevelopmentActionStatus::Scheduled, DevelopmentActionStatus::OnHold],
            DevelopmentActionStatus::InProgress, 'started', actorUserId: $actorUserId);
    }

    public function putOnHold(int $companyEntityId, int $actionId, string $reason, ?int $actorUserId = null): DevelopmentAction
    {
        $this->requireText($reason, 'A hold reason is required.');

        return $this->transition($companyEntityId, $actionId,
            [DevelopmentActionStatus::NotStarted, DevelopmentActionStatus::Scheduled, DevelopmentActionStatus::InProgress],
            DevelopmentActionStatus::OnHold, 'put_on_hold', comment: $reason, actorUserId: $actorUserId);
    }

    public function completeIntervention(
        int $companyEntityId,
        int $actionId,
        string $evidence,
        DateTimeInterface $reassessmentDue,
        ?int $actorUserId = null,
        ?int $assignedEvaluatorUserId = null,
    ): DevelopmentAction {
        $this->requireText($evidence, 'Completion evidence is required.');
        if (Carbon::instance(\DateTimeImmutable::createFromInterface($reassessmentDue))->startOfDay()->isBefore(today())) {
            throw new InvalidDevelopmentActionException('Reassessment due date cannot be before today.');
        }

        return DB::transaction(function () use ($companyEntityId, $actionId, $evidence, $reassessmentDue, $actorUserId, $assignedEvaluatorUserId): DevelopmentAction {
            $action = $this->transition($companyEntityId, $actionId,
                [DevelopmentActionStatus::NotStarted, DevelopmentActionStatus::Scheduled, DevelopmentActionStatus::InProgress, DevelopmentActionStatus::OnHold],
                DevelopmentActionStatus::PendingReassessment, 'intervention_completed', evidence: $evidence,
                actorUserId: $actorUserId, attributes: [
                    'closure_status' => DevelopmentActionClosure::PendingReassessment,
                    'completed_at' => now(),
                    'completion_evidence' => trim($evidence),
                    'reassessment_due' => $reassessmentDue,
                ]);

            // Completing an intervention opens reassessment work; it never changes proficiency.
            app(ReassessmentRequestStore::class)->requestFromDevelopmentAction(
                $companyEntityId,
                $action,
                $reassessmentDue,
                assignedEvaluatorUserId: $assignedEvaluatorUserId,
                createdByUserId: $actorUserId,
            );

            return $action;
        });
    }

    public function linkReassessment(
        int $companyEntityId,
        int $actionId,
        int $assessmentId,
        ?int $actorUserId = null,
    ): DevelopmentAction {
        return DB::transaction(function () use ($companyEntityId, $actionId, $assessmentId, $actorUserId): DevelopmentAction {
            $action = $this->find($companyEntityId, $actionId, true);
            if ($action->status !== DevelopmentActionStatus::PendingReassessment) {
                throw new InvalidDevelopmentActionException('Only an intervention pending reassessment can be verified.');
            }

            $assessment = SkillAssessment::query()
                ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
                ->whereKey($assessmentId)
                ->first()
                ?? throw new InvalidDevelopmentActionException('The post-training assessment was not found in this company.');

            if ($assessment->status !== AssessmentStatus::Finalized
                || $assessment->cycle !== AssessmentCycle::PostTraining
                || (int) $assessment->employee_entity_id !== (int) $action->employee_entity_id
                || (int) $assessment->skill_id !== (int) $action->skill_id
                || $assessment->assessed_at->lessThan($action->completed_at)) {
                throw new InvalidDevelopmentActionException('Reassessment must be finalized after completion for the same employee and skill.');
            }

            $closure = (int) $assessment->assessed_level >= (int) $action->target_level
                ? DevelopmentActionClosure::ClosedCompetent
                : DevelopmentActionClosure::FurtherActionRequired;
            $from = $action->status;
            $action->update([
                'post_assessment_id' => $assessment->id,
                'post_level' => $assessment->assessed_level,
                'improvement' => (int) $assessment->assessed_level - (int) $action->starting_level,
                'status' => DevelopmentActionStatus::Completed,
                'closure_status' => $closure,
            ]);
            $this->record($action, 'reassessment_linked', $from, DevelopmentActionStatus::Completed, null,
                $assessment->evidence, $actorUserId, ['assessment_id' => $assessment->id, 'closure' => $closure->value]);

            $openRequest = ReassessmentRequest::query()
                ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
                ->where('source_development_action_id', $action->getKey())
                ->where('status', ReassessmentRequestStatus::Open->value)
                ->first();
            if ($openRequest !== null) {
                app(ReassessmentRequestStore::class)->fulfill(
                    $companyEntityId,
                    (int) $openRequest->getKey(),
                    (int) $assessment->getKey(),
                );
            }

            return $action->refresh();
        });
    }

    public function cancel(int $companyEntityId, int $actionId, string $reason, ?int $actorUserId = null): DevelopmentAction
    {
        $this->requireText($reason, 'A cancellation reason is required.');

        return $this->transition($companyEntityId, $actionId, [
            DevelopmentActionStatus::Proposed,
            DevelopmentActionStatus::NotStarted,
            DevelopmentActionStatus::Scheduled,
            DevelopmentActionStatus::InProgress,
            DevelopmentActionStatus::OnHold,
            DevelopmentActionStatus::PendingReassessment,
        ], DevelopmentActionStatus::Cancelled, 'cancelled', comment: $reason, actorUserId: $actorUserId,
            attributes: ['closure_status' => DevelopmentActionClosure::Cancelled]);
    }

    public function comment(int $companyEntityId, int $actionId, string $comment, ?string $evidence = null, ?int $actorUserId = null): void
    {
        $this->requireText($comment, 'A comment is required.');
        $this->record($this->find($companyEntityId, $actionId), 'commented', null, null, trim($comment), $evidence, $actorUserId);
    }

    /** Operational query keeps every still-actionable lifecycle state visible. */
    public function operationalQuery(int $companyEntityId): Builder
    {
        return DevelopmentAction::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->whereNotIn('status', [DevelopmentActionStatus::Completed->value, DevelopmentActionStatus::Cancelled->value])
            ->orderByDesc('mandatory_gate')
            ->orderByDesc('priority_score')
            ->orderBy('due_date');
    }

    /** Completed and cancelled commitments remain an auditable operational register. */
    public function terminalQuery(int $companyEntityId): Builder
    {
        return DevelopmentAction::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->whereIn('status', [DevelopmentActionStatus::Completed->value, DevelopmentActionStatus::Cancelled->value])
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at');
    }

    private function create(int $companyEntityId, DevelopmentActionDraft $draft, ?int $assessmentId, ?int $actorUserId): DevelopmentAction
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->validateDraft($companyEntityId, $draft);
        $gap = max((int) $draft->targetLevel - (int) $draft->startingLevel, 0);
        $criticality = $draft->criticality ?? RequirementCriticality::Development;

        if ($assessmentId !== null && DevelopmentAction::query()->forCompany($tenantId, $companyEntityId)
            ->where('source_assessment_id', $assessmentId)
            ->exists()) {
            throw new InvalidDevelopmentActionException("Assessment [$assessmentId] already has a development action.");
        }

        $employee = $this->employee($companyEntityId, $draft->employeeEntityId);
        $departmentName = $employee->organization_entity_id === null ? null
            : WorkforceOrganizationUnitProjection::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('workforce_entity_id', $employee->organization_entity_id)
                ->where('active', true)
                ->value('name');
        $positionName = $employee->position_entity_id === null ? null
            : WorkforcePositionProjection::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('workforce_entity_id', $employee->position_entity_id)
                ->where('active', true)
                ->value('name');
        $action = DevelopmentAction::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'action_key' => (string) Str::uuid(),
            'employee_entity_id' => $draft->employeeEntityId,
            'skill_id' => $draft->skillId,
            'source_assessment_id' => $assessmentId,
            'training_course_code' => $draft->trainingCourseCode,
            'employee_name_snapshot' => $employee->display_name,
            'department_snapshot' => $departmentName,
            'position_snapshot' => $positionName,
            'starting_level' => $draft->startingLevel,
            'target_level' => $draft->targetLevel,
            'gap_at_start' => $gap,
            'criticality' => $criticality,
            'mandatory_gate' => $draft->mandatoryGate,
            'priority_score' => $this->priority->score($gap, $criticality),
            'priority_explanation' => $this->priority->explanation($gap, $criticality, $draft->mandatoryGate),
            'manual_reason' => $draft->manualReason === null ? null : trim($draft->manualReason),
            'action_type' => $draft->type,
            'objective' => trim($draft->objective),
            'intervention' => trim($draft->intervention),
            'expected_evidence' => trim($draft->expectedEvidence),
            'owner_employee_entity_id' => $draft->ownerEmployeeEntityId,
            'hr_coordinator_employee_entity_id' => $draft->hrCoordinatorEmployeeEntityId,
            'trainer_employee_entity_id' => $draft->trainerEmployeeEntityId,
            'trainer_provider_name' => $draft->trainerProviderName === null ? null : trim($draft->trainerProviderName),
            'start_date' => $draft->startDate,
            'due_date' => $draft->dueDate,
            'status' => DevelopmentActionStatus::Proposed,
            'closure_status' => DevelopmentActionClosure::Open,
            'next_steps' => $draft->nextSteps,
            'created_by_user_id' => $actorUserId,
        ]);
        $this->record($action, $assessmentId === null ? 'manually_proposed' : 'gap_proposed', null, DevelopmentActionStatus::Proposed, null, null, $actorUserId);

        return $action;
    }

    private function validateDraft(int $companyEntityId, DevelopmentActionDraft $draft): void
    {
        foreach ([$draft->objective, $draft->intervention, $draft->expectedEvidence] as $text) {
            $this->requireText($text, 'Objective, intervention, and expected evidence are required.');
        }
        if ($draft->skillId === null || $draft->startingLevel === null || $draft->targetLevel === null || $draft->criticality === null) {
            throw new InvalidDevelopmentActionException('The skill, levels, and criticality must be defined.');
        }
        if ($draft->startingLevel < 0 || $draft->startingLevel > 5 || $draft->targetLevel < 0 || $draft->targetLevel > 5) {
            throw new InvalidDevelopmentActionException('Starting and target levels must be between 0 and 5.');
        }
        if (Carbon::instance(\DateTimeImmutable::createFromInterface($draft->dueDate))->startOfDay()
            ->lessThan(Carbon::instance(\DateTimeImmutable::createFromInterface($draft->startDate))->startOfDay())) {
            throw new InvalidDevelopmentActionException('Due date cannot be before the start date.');
        }
        if ($draft->type->requiresTrainer() && $draft->trainerEmployeeEntityId === null && trim((string) $draft->trainerProviderName) === '') {
            throw new InvalidDevelopmentActionException("{$draft->type->label()} requires a trainer, coach, or provider.");
        }
        Skill::query()->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)->whereKey($draft->skillId)->first()
            ?? throw new InvalidDevelopmentActionException('The skill was not found in this company.');
        if ($draft->trainingCourseCode !== null) {
            TrainingCourse::query()->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
                ->where('code', $draft->trainingCourseCode)->first()
                ?? throw new InvalidDevelopmentActionException('The training course was not found in this company.');
        }
        foreach (array_filter([$draft->employeeEntityId, $draft->ownerEmployeeEntityId, $draft->hrCoordinatorEmployeeEntityId, $draft->trainerEmployeeEntityId]) as $employeeId) {
            $this->employee($companyEntityId, (int) $employeeId);
        }
    }

    private function assertActionableAssessment(int $companyEntityId, SkillAssessment $assessment): void
    {
        if ($assessment->status !== AssessmentStatus::Finalized || $assessment->assessed_level === null) {
            throw new InvalidDevelopmentActionException('Development actions require finalized assessments.');
        }
        $expiredCritical = $assessment->criticality === RequirementCriticality::Critical
            && $assessment->valid_until !== null && $assessment->valid_until->isPast();
        if ((int) $assessment->gap <= 0 && ! $expiredCritical) {
            throw new InvalidDevelopmentActionException('Only a skill gap or expired critical skill is actionable.');
        }
        $isCurrent = EmployeeSkillScore::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('employee_entity_id', $assessment->employee_entity_id)
            ->where('skill_id', $assessment->skill_id)
            ->where('source_assessment_id', $assessment->id)
            ->exists();
        if (! $isCurrent) {
            throw new InvalidDevelopmentActionException('Only the employee’s current valid assessment gap can create an action.');
        }
    }

    private function employee(int $companyEntityId, int $employeeEntityId): WorkforceEmployeeProjection
    {
        return WorkforceEmployeeProjection::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('workforce_entity_id', $employeeEntityId)
            ->where('active', true)
            ->first()
            ?? throw new InvalidDevelopmentActionException("Employee [$employeeEntityId] is not active in this company workforce.");
    }

    /** @param list<DevelopmentActionStatus> $allowed @param array<string, mixed> $attributes */
    private function transition(int $companyEntityId, int $actionId, array $allowed, DevelopmentActionStatus $to,
        string $event, ?string $comment = null, ?string $evidence = null, ?int $actorUserId = null, array $attributes = []): DevelopmentAction
    {
        return DB::transaction(function () use ($companyEntityId, $actionId, $allowed, $to, $event, $comment, $evidence, $actorUserId, $attributes): DevelopmentAction {
            $action = $this->find($companyEntityId, $actionId, true);
            if (! in_array($action->status, $allowed, true)) {
                throw new InvalidDevelopmentActionException("Cannot move {$action->status->label()} to {$to->label()}.");
            }
            $from = $action->status;
            $action->update(array_merge($attributes, ['status' => $to]));
            $this->record($action, $event, $from, $to, $comment, $evidence, $actorUserId);

            return $action->refresh();
        });
    }

    private function find(int $companyEntityId, int $actionId, bool $lock = false): DevelopmentAction
    {
        $query = DevelopmentAction::query()->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)->whereKey($actionId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new InvalidDevelopmentActionException('Development action was not found in this company.');
    }

    /** @param array<string, mixed>|null $metadata */
    private function record(DevelopmentAction $action, string $event, ?DevelopmentActionStatus $from, ?DevelopmentActionStatus $to,
        ?string $comment, ?string $evidence, ?int $actorUserId, ?array $metadata = null): void
    {
        DevelopmentActionAuditEvent::query()->create([
            'tenant_id' => $action->tenant_id,
            'company_entity_id' => $action->company_entity_id,
            'development_action_id' => $action->id,
            'event_type' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'comment' => $comment,
            'evidence' => $evidence,
            'actor_user_id' => $actorUserId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function requireText(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidDevelopmentActionException($message);
        }
    }
}
