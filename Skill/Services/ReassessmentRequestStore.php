<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\ReassessmentRequestDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestSource;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidReassessmentRequestException;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentAction;
use App\Domains\PeopleConnector\Skill\Models\ReassessmentRequest;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Connector-owned reassessment work items. Training/action completion may open a
 * request; only a later finalized Assessment Log row updates proficiency.
 */
final class ReassessmentRequestStore
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function request(int $companyEntityId, ReassessmentRequestDraft $draft, ?int $createdByUserId = null): ReassessmentRequest
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertEntity($tenantId, $companyEntityId, WorkforceResourceType::Company);
        $this->assertEntity($tenantId, $draft->employeeEntityId, WorkforceResourceType::Employee);

        $skill = Skill::query()->forCompany($tenantId, $companyEntityId)->whereKey($draft->skillId)->first()
            ?? throw new InvalidReassessmentRequestException("Skill [{$draft->skillId}] was not found in this company catalog.");

        if (! $skill->active) {
            throw new InvalidReassessmentRequestException("Skill [{$skill->code}] is inactive.");
        }

        if ($draft->targetLevel < 0 || $draft->targetLevel > 5) {
            throw new InvalidReassessmentRequestException('Target level must be between 0 and 5.');
        }

        $due = Carbon::instance(\DateTimeImmutable::createFromInterface($draft->dueDate))->startOfDay();
        if ($due->isBefore(today())) {
            throw new InvalidReassessmentRequestException('Reassessment due date cannot be before today.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $draft, $due, $createdByUserId): ReassessmentRequest {
            if ($draft->sourceDevelopmentActionId !== null) {
                $existing = ReassessmentRequest::query()
                    ->forCompany($tenantId, $companyEntityId)
                    ->where('source_development_action_id', $draft->sourceDevelopmentActionId)
                    ->where('status', ReassessmentRequestStatus::Open->value)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                DevelopmentAction::query()->forCompany($tenantId, $companyEntityId)
                    ->whereKey($draft->sourceDevelopmentActionId)
                    ->first()
                    ?? throw new InvalidReassessmentRequestException('The source development action was not found in this company.');
            }

            $openDuplicate = ReassessmentRequest::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('employee_entity_id', $draft->employeeEntityId)
                ->where('skill_id', $draft->skillId)
                ->where('status', ReassessmentRequestStatus::Open->value)
                ->first();
            if ($openDuplicate !== null) {
                throw new InvalidReassessmentRequestException(
                    'An open reassessment request already exists for this employee and skill.',
                );
            }

            return ReassessmentRequest::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'employee_entity_id' => $draft->employeeEntityId,
                'skill_id' => $draft->skillId,
                'target_level' => $draft->targetLevel,
                'cycle' => $draft->cycle,
                'source' => $draft->source,
                'status' => ReassessmentRequestStatus::Open,
                'due_date' => $due,
                'assigned_evaluator_user_id' => $draft->assignedEvaluatorUserId,
                'required_evidence' => $draft->requiredEvidence === null ? null : trim($draft->requiredEvidence),
                'notes' => $draft->notes === null ? null : trim($draft->notes),
                'source_development_action_id' => $draft->sourceDevelopmentActionId,
                'source_training_event_id' => $draft->sourceTrainingEventId,
                'source_assessment_id' => $draft->sourceAssessmentId,
                'created_by_user_id' => $createdByUserId,
            ]);
        });
    }

    public function requestFromDevelopmentAction(
        int $companyEntityId,
        DevelopmentAction $action,
        DateTimeInterface $dueDate,
        ?int $assignedEvaluatorUserId = null,
        ?int $createdByUserId = null,
    ): ReassessmentRequest {
        return $this->request($companyEntityId, new ReassessmentRequestDraft(
            employeeEntityId: (int) $action->employee_entity_id,
            skillId: (int) $action->skill_id,
            dueDate: $dueDate,
            cycle: AssessmentCycle::PostTraining,
            source: ReassessmentRequestSource::DevelopmentAction,
            targetLevel: (int) $action->target_level,
            assignedEvaluatorUserId: $assignedEvaluatorUserId,
            requiredEvidence: $action->expected_evidence,
            sourceDevelopmentActionId: (int) $action->getKey(),
            notes: 'Opened when the development intervention completed.',
        ), $createdByUserId);
    }

    public function fulfill(int $companyEntityId, int $requestId, int $assessmentId): ReassessmentRequest
    {
        return DB::transaction(function () use ($companyEntityId, $requestId, $assessmentId): ReassessmentRequest {
            $tenantId = $this->tenantContext->requireTenantId();
            $request = ReassessmentRequest::query()->forCompany($tenantId, $companyEntityId)->whereKey($requestId)->lockForUpdate()->first()
                ?? throw new InvalidReassessmentRequestException("Reassessment request [$requestId] was not found.");

            if ($request->status !== ReassessmentRequestStatus::Open) {
                throw new InvalidReassessmentRequestException('Only an open reassessment request can be fulfilled.');
            }

            $assessment = SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->whereKey($assessmentId)->first()
                ?? throw new InvalidReassessmentRequestException('The reassessment was not found in this company.');

            if ($assessment->status !== AssessmentStatus::Finalized
                || (int) $assessment->employee_entity_id !== (int) $request->employee_entity_id
                || (int) $assessment->skill_id !== (int) $request->skill_id
                || $assessment->cycle !== $request->cycle) {
                throw new InvalidReassessmentRequestException(
                    'Fulfillment requires a finalized assessment for the same employee, skill, and cycle.',
                );
            }

            $request->update([
                'status' => ReassessmentRequestStatus::Fulfilled,
                'fulfilled_assessment_id' => $assessment->getKey(),
                'fulfilled_at' => now(),
            ]);

            return $request->refresh();
        });
    }

    public function cancel(int $companyEntityId, int $requestId, string $reason): ReassessmentRequest
    {
        if (trim($reason) === '') {
            throw new InvalidReassessmentRequestException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($companyEntityId, $requestId, $reason): ReassessmentRequest {
            $request = ReassessmentRequest::query()
                ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first()
                ?? throw new InvalidReassessmentRequestException("Reassessment request [$requestId] was not found.");

            if ($request->status !== ReassessmentRequestStatus::Open) {
                throw new InvalidReassessmentRequestException('Only an open reassessment request can be cancelled.');
            }

            $request->update([
                'status' => ReassessmentRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ]);

            return $request->refresh();
        });
    }

    public function openQuery(int $companyEntityId): Builder
    {
        return ReassessmentRequest::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('status', ReassessmentRequestStatus::Open->value)
            ->orderBy('due_date')
            ->orderBy('id');
    }

    private function assertEntity(int $tenantId, int $entityId, WorkforceResourceType $type): void
    {
        $exists = WorkforceEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($entityId)
            ->where('resource_type', $type->value)
            ->exists();

        if (! $exists) {
            throw new InvalidReassessmentRequestException(
                "Workforce {$type->value} entity [$entityId] was not found in the current tenant.",
            );
        }
    }
}
