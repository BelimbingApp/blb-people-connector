<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\HodVerification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Events\SkillAssessmentFinalized;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
    ) {}

    public function draft(int $companyEntityId, AssessmentDraft $draft, array $employeeData = []): SkillAssessment
    {
        return $this->write($companyEntityId, $draft, AssessmentStatus::Draft, employeeData: $employeeData);
    }

    public function submit(int $companyEntityId, AssessmentDraft $draft, array $employeeData = []): SkillAssessment
    {
        return $this->write($companyEntityId, $draft, AssessmentStatus::Submitted, employeeData: $employeeData);
    }

    /**
     * Finalize a new assessment (or supersede a prior finalized one).
     *
     * @param  array<string, mixed>  $employeeData  Attributes for ResolvesSkillRequirements
     */
    public function finalize(
        int $companyEntityId,
        AssessmentDraft $draft,
        array $employeeData = [],
        ?int $supersedesAssessmentId = null,
        ?int $finalizedByUserId = null,
    ): SkillAssessment {
        return DB::transaction(function () use ($companyEntityId, $draft, $employeeData, $supersedesAssessmentId, $finalizedByUserId): SkillAssessment {
            $assessment = $this->write(
                $companyEntityId,
                $draft,
                AssessmentStatus::Finalized,
                employeeData: $employeeData,
                supersedesAssessmentId: $supersedesAssessmentId,
                finalizedByUserId: $finalizedByUserId,
                finalize: true,
            );

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
     * @param  array<string, mixed>  $employeeData
     */
    private function write(
        int $companyEntityId,
        AssessmentDraft $draft,
        AssessmentStatus $status,
        array $employeeData = [],
        ?int $supersedesAssessmentId = null,
        ?int $finalizedByUserId = null,
        bool $finalize = false,
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

        $employeeData = array_merge(['company_entity_id' => $companyEntityId], $employeeData);
        $requirement = $this->requirementForSkill($employeeData, (int) $skill->getKey(), $draft->assessedAt);

        $gap = $requirement->gap($draft->assessedLevel);
        $weight = $draft->weightPercent ?? 1.0;
        $weightedGap = $gap * $weight;
        $priority = $weightedGap * $requirement->criticality->multiplier();
        $resultBand = AssessmentResultBand::fromGap($gap, $draft->assessedLevel, $requirement->requiredLevel);

        $nextDue = $this->nextDue($draft, $requirement);
        $now = now();

        if ($supersedesAssessmentId !== null) {
            $prior = SkillAssessment::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($supersedesAssessmentId)
                ->first()
                ?? throw new InvalidAssessmentException("Assessment [$supersedesAssessmentId] was not found.");

            if (! $prior->isFinalized()) {
                throw new InvalidAssessmentException('Only a finalized assessment can be superseded.');
            }

            if ((int) $prior->employee_entity_id !== $draft->employeeEntityId
                || (int) $prior->skill_id !== $draft->skillId) {
                throw new InvalidAssessmentException('Supersession must keep the same employee and skill.');
            }
        }

        return SkillAssessment::query()->create([
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
            'scale_id' => $draft->scaleId,
            'scale_version' => $draft->scaleVersion,
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
            'assessor_user_id' => $draft->assessorUserId,
            'assessor_employee_entity_id' => $draft->assessorEmployeeEntityId,
            'assessed_at' => $draft->assessedAt,
            'hod_verification' => HodVerification::Pending,
            'certificate_number' => $draft->certificateNumber,
            'valid_until' => $draft->validUntil,
            'next_assessment_due' => $nextDue,
            'supersedes_assessment_id' => $supersedesAssessmentId,
            'finalized_at' => $finalize ? $now : null,
            'finalized_by_user_id' => $finalize ? $finalizedByUserId : null,
        ]);
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
        EmployeeSkillScore::query()
            ->forCompany((int) $assessment->tenant_id, (int) $assessment->company_entity_id)
            ->updateOrCreate(
                [
                    'tenant_id' => $assessment->tenant_id,
                    'employee_entity_id' => $assessment->employee_entity_id,
                    'skill_id' => $assessment->skill_id,
                ],
                [
                    'company_entity_id' => $assessment->company_entity_id,
                    'source_assessment_id' => $assessment->getKey(),
                    'requirement_reference' => $assessment->requirement_reference,
                    'requirement_version' => $assessment->requirement_version,
                    'required_level' => $assessment->required_level,
                    'current_level' => $assessment->assessed_level,
                    'gap' => $assessment->gap,
                    'mandatory_gate' => $assessment->mandatory_gate,
                    'criticality' => $assessment->criticality instanceof RequirementCriticality
                        ? $assessment->criticality
                        : RequirementCriticality::from((string) $assessment->criticality),
                    'assessed_at' => $assessment->assessed_at,
                    'next_assessment_due' => $assessment->next_assessment_due,
                    'valid_until' => $assessment->valid_until,
                ],
            );
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
