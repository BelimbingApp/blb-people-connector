<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\HodVerification;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Events\SkillAssessmentFinalized;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Evidence-backed employee skill assessments (blb-people#12 / [0003]).
 *
 * Requirement snapshots come only from {@see ResolvesSkillRequirements} —
 * never from profile selectors. Finalized rows are immutable; corrections
 * insert a superseding finalized row and refresh the current-score projection.
 */
final class AssessmentStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolvesSkillRequirements $requirements,
        private readonly ProficiencyScaleStore $scales,
        private readonly SkillAudience $audience,
    ) {}

    public function draft(int $companyEntityId, AssessmentDraft $draft, array $employeeData = []): SkillAssessment
    {
        return $this->write($companyEntityId, $draft, AssessmentStatus::Draft, employeeData: $employeeData);
    }

    public function submit(
        User $actor,
        int $companyEntityId,
        AssessmentDraft $draft,
        array $employeeData = [],
        ?int $supersedesAssessmentId = null,
    ): SkillAssessment {
        $actorId = $this->actorId($actor);
        $this->audience->authorizeAssessmentSubmission($actor, $companyEntityId, $draft->employeeEntityId);

        if ($draft->assessorUserId !== null && $draft->assessorUserId !== $actorId) {
            throw new InvalidAssessmentException('The assessor identity must match the authenticated actor.');
        }

        return DB::transaction(function () use ($actorId, $companyEntityId, $draft, $employeeData, $supersedesAssessmentId): SkillAssessment {
            $assessment = $this->write(
                $companyEntityId,
                $draft,
                AssessmentStatus::Submitted,
                employeeData: $employeeData,
                supersedesAssessmentId: $supersedesAssessmentId,
                assessorUserId: $actorId,
            );

            $this->recordDecision($assessment, 'submitted', $actorId);

            return $assessment;
        });
    }

    /**
     * Atomically submit many cells for HOD review.
     * Empty evidence cells are skipped; partial failure rolls the whole batch back.
     *
     * @param  list<AssessmentDraft>  $drafts
     * @param  array<string, mixed>  $employeeData  Optional overrides merged under per-employee projection context
     * @return list<SkillAssessment>
     */
    public function submitBatch(
        User $actor,
        int $companyEntityId,
        array $drafts,
        array $employeeData = [],
    ): array {
        if ($drafts === []) {
            throw new InvalidAssessmentException('Batch submission requires at least one assessment cell.');
        }

        return DB::transaction(function () use ($actor, $companyEntityId, $drafts, $employeeData): array {
            $saved = [];

            foreach ($drafts as $draft) {
                if (! $draft instanceof AssessmentDraft) {
                    throw new InvalidAssessmentException('Batch cells must be AssessmentDraft instances.');
                }

                $saved[] = $this->submit(
                    $actor,
                    $companyEntityId,
                    $draft,
                    employeeData: $employeeData,
                );
            }

            return $saved;
        });
    }

    /**
     * Move an assessor submission into the HOD review queue.
     *
     * The row is locked before the transition so retries cannot skip the
     * submitted state or race a HOD decision.
     */
    public function requestHodVerification(User $actor, int $companyEntityId, int $assessmentId): SkillAssessment
    {
        $actorId = $this->actorId($actor);

        return DB::transaction(function () use ($actor, $actorId, $companyEntityId, $assessmentId): SkillAssessment {
            $assessment = $this->lockAssessment($companyEntityId, $assessmentId);

            if ((int) $assessment->assessor_user_id !== $actorId) {
                throw new InvalidAssessmentException('Only the authenticated assessor can submit this assessment for HOD verification.');
            }

            $this->audience->authorizeAssessmentSubmission($actor, $companyEntityId, (int) $assessment->employee_entity_id);

            $this->queueHodVerification($assessment, $actorId);

            return $assessment->refresh();
        });
    }

    /**
     * Atomically move a submitted matrix into the HOD review queue.
     *
     * @param  list<int>  $assessmentIds
     * @return list<SkillAssessment>
     */
    public function requestHodVerificationBatch(User $actor, int $companyEntityId, array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            throw new InvalidAssessmentException('HOD verification requires at least one assessment.');
        }

        return DB::transaction(function () use ($actor, $companyEntityId, $assessmentIds): array {
            $queued = [];

            foreach ($assessmentIds as $assessmentId) {
                if (! is_int($assessmentId)) {
                    throw new InvalidAssessmentException('Assessment ids must be integers.');
                }

                $assessment = $this->lockAssessment($companyEntityId, $assessmentId);
                if ((int) $assessment->assessor_user_id !== $this->actorId($actor)) {
                    throw new InvalidAssessmentException('Only the authenticated assessor can submit this assessment for HOD verification.');
                }
                $this->audience->authorizeAssessmentSubmission($actor, $companyEntityId, (int) $assessment->employee_entity_id);
                $this->queueHodVerification($assessment, $this->actorId($actor));
                $queued[] = $assessment->refresh();
            }

            return $queued;
        });
    }

    /**
     * Re-submit a returned assessment as a new row, retaining immutable
     * decision history through the supersession link.
     */
    public function resubmitForCorrection(
        User $actor,
        int $companyEntityId,
        int $returnedAssessmentId,
        AssessmentDraft $draft,
        array $employeeData = [],
    ): SkillAssessment {
        $actorId = $this->actorId($actor);

        return DB::transaction(function () use ($actor, $actorId, $companyEntityId, $returnedAssessmentId, $draft, $employeeData): SkillAssessment {
            $prior = $this->lockAssessment($companyEntityId, $returnedAssessmentId);

            if ($prior->status !== AssessmentStatus::Returned) {
                throw new InvalidAssessmentException('Only a returned assessment can be resubmitted for correction.');
            }

            if ((int) $prior->assessor_user_id !== $actorId) {
                throw new InvalidAssessmentException('Only the original assessor can resubmit a returned assessment.');
            }

            if ((int) $prior->employee_entity_id !== $draft->employeeEntityId
                || (int) $prior->skill_id !== $draft->skillId) {
                throw new InvalidAssessmentException('A correction must keep the same employee and skill.');
            }

            if (SkillAssessment::query()
                ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
                ->where('supersedes_assessment_id', $returnedAssessmentId)
                ->exists()) {
                throw new InvalidAssessmentException('This returned assessment already has a correction submission.');
            }

            $this->audience->authorizeAssessmentSubmission($actor, $companyEntityId, $draft->employeeEntityId);

            $submitted = $this->write(
                $companyEntityId,
                $draft,
                AssessmentStatus::Submitted,
                employeeData: $employeeData,
                supersedesAssessmentId: $returnedAssessmentId,
                assessorUserId: $actorId,
                allowReturnedSupersession: true,
            );
            $this->recordDecision($submitted, 'submitted', $actorId, 'Correction submitted for HOD review.');
            $this->queueHodVerification($submitted, $actorId);

            return $submitted->refresh();
        });
    }

    /**
     * Return an assessment without changing any score or downstream projection.
     */
    public function returnForCorrection(
        User $actor,
        int $companyEntityId,
        int $assessmentId,
        ?string $decisionNotes = null,
    ): SkillAssessment {
        $actorId = $this->actorId($actor);

        return DB::transaction(function () use ($actor, $actorId, $companyEntityId, $assessmentId, $decisionNotes): SkillAssessment {
            $assessment = $this->lockAssessment($companyEntityId, $assessmentId);
            $this->assertHodReviewable($actor, $companyEntityId, $assessment);

            if (trim((string) $decisionNotes) === '') {
                throw new InvalidAssessmentException('A return reason is required for an assessment correction request.');
            }

            $assessment->status = AssessmentStatus::Returned;
            $assessment->hod_verification = HodVerification::Rejected;
            $assessment->hod_verifier_user_id = $actorId;
            $assessment->hod_verified_at = now();
            $assessment->hod_decision_notes = $decisionNotes;
            $this->saveTransition($assessment);
            $this->recordDecision($assessment, 'returned', $actorId, $decisionNotes);

            return $assessment->refresh();
        });
    }

    /**
     * Record the independent HOD decision. Finalization remains a separate
     * transition so no score or downstream effect is visible prematurely.
     */
    public function verifyHod(
        User $actor,
        int $companyEntityId,
        int $assessmentId,
        ?string $decisionNotes = null,
    ): SkillAssessment {
        $actorId = $this->actorId($actor);

        return DB::transaction(function () use ($actor, $actorId, $companyEntityId, $assessmentId, $decisionNotes): SkillAssessment {
            $assessment = $this->lockAssessment($companyEntityId, $assessmentId);
            $this->assertHodReviewable($actor, $companyEntityId, $assessment);

            $assessment->hod_verification = HodVerification::Verified;
            $assessment->hod_verifier_user_id = $actorId;
            $assessment->hod_verified_at = now();
            $assessment->hod_decision_notes = $decisionNotes;
            $this->saveTransition($assessment);
            $this->recordDecision($assessment, 'verified', $actorId, $decisionNotes);

            return $assessment->refresh();
        });
    }

    /**
     * Finalize only a row carrying a committed, independent HOD verification.
     */
    public function finalizeVerified(
        User $actor,
        int $companyEntityId,
        int $assessmentId,
    ): SkillAssessment {
        $finalizedByUserId = $this->actorId($actor);

        return DB::transaction(function () use ($actor, $companyEntityId, $assessmentId, $finalizedByUserId): SkillAssessment {
            $assessment = $this->lockAssessment($companyEntityId, $assessmentId);

            $this->audience->authorizeAssessmentFinalization($actor, $companyEntityId, (int) $assessment->employee_entity_id);

            if ($assessment->status !== AssessmentStatus::PendingHodVerification) {
                throw new InvalidAssessmentException(
                    'Only an assessment pending HOD verification can be finalized.',
                );
            }

            if (! $assessment->isHodVerified()) {
                throw new InvalidAssessmentException(
                    'HOD verification is required before an assessment can be finalized.',
                );
            }

            if ($assessment->assessor_user_id === null
                || (int) $assessment->assessor_user_id === $finalizedByUserId) {
                throw new InvalidAssessmentException('The assessor cannot finalize their own assessment.');
            }

            $assessment->status = AssessmentStatus::Finalized;
            $assessment->finalized_at = now();
            $assessment->finalized_by_user_id = $finalizedByUserId;
            $this->saveTransition($assessment);
            $this->recordDecision($assessment, 'finalized', $finalizedByUserId);

            $this->projectCurrentScore($assessment);

            Event::dispatch(new SkillAssessmentFinalized(
                (int) $assessment->tenant_id,
                (int) $assessment->getKey(),
                (int) $assessment->employee_entity_id,
                (int) $assessment->skill_id,
            ));

            return $assessment;
        });
    }

    /**
     * Direct draft finalization is intentionally removed from the public
     * workflow. Keeping this method as a loud failure protects older callers
     * from silently bypassing HOD verification.
     *
     * @param  array<string, mixed>  $employeeData  Attributes for ResolvesSkillRequirements
     */
    public function finalize(
        int $companyEntityId,
        AssessmentDraft $draft,
        array $employeeData = [],
        ?int $supersedesAssessmentId = null,
        ?int $finalizedByUserId = null,
    ): never {
        throw new InvalidAssessmentException(
            'Direct finalization is disabled; submit, request HOD verification, verify HOD, then finalizeVerified.',
        );
    }

    /**
     * @param  list<AssessmentDraft>  $drafts
     * @param  array<string, mixed>  $employeeData
     */
    public function finalizeBatch(
        int $companyEntityId,
        array $drafts,
        array $employeeData = [],
        ?int $finalizedByUserId = null,
    ): never {
        throw new InvalidAssessmentException(
            'Direct batch finalization is disabled; submit each cell for HOD verification first.',
        );
    }

    /**
     * @param  array<string, mixed>  $employeeData
     */
    private function write(
        int $companyEntityId,
        AssessmentDraft $draft,
        AssessmentStatus $status,
        array $employeeData = [],
        ?int $supersedesAssessmentId = null,
        ?int $assessorUserId = null,
        bool $allowReturnedSupersession = false,
    ): SkillAssessment {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertEntity($tenantId, $companyEntityId, WorkforceResourceType::Company);
        $this->assertEntity($tenantId, $draft->employeeEntityId, WorkforceResourceType::Employee);

        if (trim($draft->evidence) === '') {
            throw new InvalidAssessmentException('Evidence is mandatory; score-by-impression is invalid.');
        }

        if ($draft->assessedLevel < 0 || $draft->assessedLevel > 5) {
            throw new InvalidAssessmentException('Assessed level must be between 0 and 5.');
        }

        $skill = Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereKey($draft->skillId)
            ->first()
            ?? throw new InvalidAssessmentException("Skill [{$draft->skillId}] was not found in this company catalog.");

        if (! $skill->active) {
            throw new InvalidAssessmentException("Skill [{$skill->code}] is inactive.");
        }

        $employeeData = $this->employeeRequirementContext(
            $companyEntityId,
            $draft->employeeEntityId,
            $employeeData,
        );
        $requirement = $this->requirementForSkill($employeeData, (int) $skill->getKey(), $draft->assessedAt);
        $scale = $this->resolveScaleSnapshot($companyEntityId, $draft);

        $gap = $requirement->gap($draft->assessedLevel);
        $weight = $draft->weightPercent ?? 1.0;
        $weightedGap = $gap * $weight;
        $priority = $weightedGap * $requirement->criticality->multiplier();
        $resultBand = AssessmentResultBand::fromGap($gap, $draft->assessedLevel, $requirement->requiredLevel);

        $nextDue = $this->nextDue($draft, $requirement);
        $assessorUserId ??= $draft->assessorUserId;

        if ($status !== AssessmentStatus::Draft && $assessorUserId === null) {
            throw new InvalidAssessmentException('Submitted assessments require an authenticated assessor.');
        }

        if ($supersedesAssessmentId !== null) {
            $prior = SkillAssessment::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($supersedesAssessmentId)
                ->first()
                ?? throw new InvalidAssessmentException("Assessment [$supersedesAssessmentId] was not found.");

            if (! $prior->isFinalized()
                && ! ($allowReturnedSupersession && $prior->status === AssessmentStatus::Returned)) {
                throw new InvalidAssessmentException('Only a finalized assessment can be superseded.');
            }

            if ((int) $prior->employee_entity_id !== $draft->employeeEntityId
                || (int) $prior->skill_id !== $draft->skillId) {
                throw new InvalidAssessmentException('Supersession must keep the same employee and skill.');
            }
        }

        $create = fn (): SkillAssessment => SkillAssessment::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'employee_entity_id' => $draft->employeeEntityId,
            'skill_id' => $draft->skillId,
            'requirement_reference' => $requirement->requirementReference,
            'requirement_version' => $requirement->requirementVersion,
            'required_level' => $requirement->requiredLevel,
            'criticality' => $requirement->criticality,
            'weight_percent' => $weight,
            'mandatory_gate' => $requirement->mandatoryGate,
            'scale_id' => $scale['id'],
            'scale_version' => $scale['version'],
            'assessed_level' => $draft->assessedLevel,
            'gap' => $gap,
            'weighted_gap' => $weightedGap,
            'priority_score' => $priority,
            'result_band' => $resultBand,
            'method' => $draft->method,
            'cycle' => $draft->cycle,
            'status' => $status,
            'evidence' => $draft->evidence,
            'notes' => $draft->notes,
            'assessor_user_id' => $assessorUserId,
            'assessor_employee_entity_id' => $draft->assessorEmployeeEntityId,
            'assessed_at' => $draft->assessedAt,
            'hod_verification' => HodVerification::Pending,
            'certificate_number' => $draft->certificateNumber,
            'valid_until' => $draft->validUntil,
            'next_assessment_due' => $nextDue,
            'supersedes_assessment_id' => $supersedesAssessmentId,
            'finalized_at' => null,
            'finalized_by_user_id' => null,
        ]);

        return $status === AssessmentStatus::Draft
            ? $create()
            : $this->withWorkflowContext($create);
    }

    /**
     * Build opaque ResolvesSkillRequirements context from the workforce projection.
     * Caller overrides win, but company/department/position from the spine fill gaps.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeeRequirementContext(int $companyEntityId, int $employeeEntityId, array $overrides): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $context = array_merge([
            'company_entity_id' => $companyEntityId,
        ], $overrides);
        $context['company_entity_id'] = $companyEntityId;

        $projection = WorkforceEmployeeProjection::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('workforce_entity_id', $employeeEntityId)
            ->first();

        if ($projection === null) {
            return $context;
        }

        if (! array_key_exists('department_entity_id', $overrides)
            && $projection->organization_entity_id !== null) {
            $context['department_entity_id'] = (int) $projection->organization_entity_id;
        }

        if (! array_key_exists('position_entity_id', $overrides)
            && $projection->position_entity_id !== null) {
            $context['position_entity_id'] = (int) $projection->position_entity_id;
        }

        return $context;
    }

    /**
     * @return array{id: int, version: int}
     */
    private function resolveScaleSnapshot(int $companyEntityId, AssessmentDraft $draft): array
    {
        if ($draft->scaleId !== null && $draft->scaleVersion !== null) {
            return ['id' => $draft->scaleId, 'version' => $draft->scaleVersion];
        }

        $scale = $this->scales->currentScale($companyEntityId, SkillCatalogDefaults::SCALE_CODE)
            ?? $this->publishedScaleFallback($companyEntityId);

        if ($scale === null) {
            throw new InvalidAssessmentException(
                'A published proficiency scale is required before assessments can be submitted.',
            );
        }

        return ['id' => (int) $scale->getKey(), 'version' => (int) $scale->version];
    }

    private function publishedScaleFallback(int $companyEntityId): ?ProficiencyScale
    {
        return ProficiencyScale::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('status', ProficiencyScaleStatus::Published->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $employeeData
     */
    private function requirementForSkill(array $employeeData, int $skillId, DateTimeInterface $asOf): ResolvedSkillRequirement
    {
        foreach ($this->requirements->requirementsFor($employeeData, $asOf) as $requirement) {
            if ($requirement->skillId === $skillId) {
                return $requirement;
            }
        }

        throw new InvalidAssessmentException(
            "No applicable skill requirement for skill [$skillId] under the published contract.",
        );
    }

    private function nextDue(AssessmentDraft $draft, ResolvedSkillRequirement $requirement): ?CarbonInterface
    {
        if ($draft->validUntil !== null) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($draft->validUntil))->startOfDay();
        }

        return Carbon::instance(\DateTimeImmutable::createFromInterface($draft->assessedAt))
            ->addMonths(12)
            ->startOfDay();
    }

    private function projectCurrentScore(SkillAssessment $assessment): void
    {
        $latest = SkillAssessment::query()
            ->forCompany((int) $assessment->tenant_id, (int) $assessment->company_entity_id)
            ->where('employee_entity_id', $assessment->employee_entity_id)
            ->where('skill_id', $assessment->skill_id)
            ->where('status', AssessmentStatus::Finalized->value)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return;
        }

        EmployeeSkillScore::query()
            ->forCompany((int) $latest->tenant_id, (int) $latest->company_entity_id)
            ->updateOrCreate(
                [
                    'tenant_id' => $latest->tenant_id,
                    'employee_entity_id' => $latest->employee_entity_id,
                    'skill_id' => $latest->skill_id,
                ],
                [
                    'company_entity_id' => $latest->company_entity_id,
                    'source_assessment_id' => $latest->getKey(),
                    'requirement_reference' => $latest->requirement_reference,
                    'requirement_version' => $latest->requirement_version,
                    'required_level' => $latest->required_level,
                    'current_level' => $latest->assessed_level,
                    'gap' => $latest->gap,
                    'mandatory_gate' => $latest->mandatory_gate,
                    'criticality' => $latest->criticality instanceof RequirementCriticality
                        ? $latest->criticality
                        : RequirementCriticality::from((string) $latest->criticality),
                    'assessed_at' => $latest->assessed_at,
                    'next_assessment_due' => $latest->next_assessment_due,
                    'valid_until' => $latest->valid_until,
                ],
            );
    }

    private function lockAssessment(int $companyEntityId, int $assessmentId): SkillAssessment
    {
        $tenantId = $this->tenantContext->requireTenantId();

        return SkillAssessment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereKey($assessmentId)
            ->lockForUpdate()
            ->first()
            ?? throw new InvalidAssessmentException("Assessment [$assessmentId] was not found.");
    }

    private function assertHodReviewable(User $actor, int $companyEntityId, SkillAssessment $assessment): void
    {
        if ($assessment->status !== AssessmentStatus::PendingHodVerification
            || $assessment->hod_verification !== HodVerification::Pending) {
            throw new InvalidAssessmentException(
                'Only a pending, undecided assessment can receive an HOD decision.',
            );
        }

        if ($assessment->assessor_user_id === null) {
            throw new InvalidAssessmentException('An assessment without an assessor cannot receive an HOD decision.');
        }

        if ((int) $assessment->assessor_user_id === $this->actorId($actor)) {
            throw new InvalidAssessmentException('The assessor cannot verify their own assessment.');
        }

        $this->audience->authorizeHodVerification($actor, $companyEntityId, (int) $assessment->employee_entity_id);
    }

    private function queueHodVerification(SkillAssessment $assessment, int $actorUserId): void
    {
        if ($assessment->status !== AssessmentStatus::Submitted) {
            throw new InvalidAssessmentException(
                'Only a submitted assessment can enter HOD verification.',
            );
        }

        $assessment->status = AssessmentStatus::PendingHodVerification;
        $this->saveTransition($assessment);
        $this->recordDecision($assessment, 'queued', $actorUserId);
    }

    private function saveTransition(SkillAssessment $assessment): void
    {
        $this->withWorkflowContext(static fn (): bool => $assessment->save());
    }

    private function withWorkflowContext(Closure $callback): mixed
    {
        return AssessmentWorkflowContext::runStoreMutation($callback);
    }

    private function actorId(User $actor): int
    {
        $id = $actor->getAuthIdentifier();

        if (! is_numeric($id) || (int) $id < 1) {
            throw new InvalidAssessmentException('Assessment workflow requires a persisted authenticated user.');
        }

        return (int) $id;
    }

    private function recordDecision(
        SkillAssessment $assessment,
        string $decision,
        int $actorUserId,
        ?string $notes = null,
    ): void {
        $this->withWorkflowContext(function () use ($assessment, $decision, $actorUserId, $notes): void {
            $assessment->decisions()->create([
                'tenant_id' => (int) $assessment->tenant_id,
                'company_entity_id' => (int) $assessment->company_entity_id,
                'employee_entity_id' => (int) $assessment->employee_entity_id,
                'skill_id' => (int) $assessment->skill_id,
                'decision' => $decision,
                'actor_user_id' => $actorUserId,
                'notes' => $notes,
                'created_at' => now(),
            ]);
        });
    }

    private function assertEntity(int $tenantId, int $entityId, WorkforceResourceType $type): void
    {
        $exists = WorkforceEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($entityId)
            ->where('resource_type', $type->value)
            ->exists();

        if (! $exists) {
            throw new InvalidAssessmentException(
                "Workforce {$type->value} entity [$entityId] was not found in the current tenant.",
            );
        }
    }
}
