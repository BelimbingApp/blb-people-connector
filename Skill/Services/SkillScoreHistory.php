<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentAction;
use App\Domains\PeopleConnector\Skill\Models\ReassessmentRequest;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use Illuminate\Support\Collection;

/**
 * Append-only employee×skill proficiency trail: finalized assessments plus linked
 * development actions and reassessment requests. Never invents or mutates scores.
 */
final class SkillScoreHistory
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Finalized Assessment Log rows for one employee and skill, oldest first.
     *
     * @return Collection<int, SkillAssessment>
     */
    public function assessments(int $companyEntityId, int $employeeEntityId, int $skillId): Collection
    {
        return SkillAssessment::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('employee_entity_id', $employeeEntityId)
            ->where('skill_id', $skillId)
            ->where('status', AssessmentStatus::Finalized->value)
            ->orderBy('assessed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Interpretable timeline entries for workbook parity (assessments, actions, requests).
     *
     * @return list<array{kind: string, at: string, id: int, summary: string, meta: array<string, mixed>}>
     */
    public function timeline(int $companyEntityId, int $employeeEntityId, int $skillId): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $entries = [];

        foreach ($this->assessments($companyEntityId, $employeeEntityId, $skillId) as $assessment) {
            $entries[] = [
                'kind' => 'assessment',
                'at' => $assessment->assessed_at->toIso8601String(),
                'id' => (int) $assessment->getKey(),
                'summary' => sprintf(
                    'Finalized %s at level %d (required %d)',
                    $assessment->cycle instanceof \BackedEnum ? $assessment->cycle->value : (string) $assessment->cycle,
                    (int) $assessment->assessed_level,
                    (int) $assessment->required_level,
                ),
                'meta' => [
                    'assessed_level' => (int) $assessment->assessed_level,
                    'required_level' => (int) $assessment->required_level,
                    'requirement_reference' => $assessment->requirement_reference,
                    'requirement_version' => (int) $assessment->requirement_version,
                    'valid_until' => $assessment->valid_until?->toDateString(),
                    'gap' => (int) $assessment->gap,
                ],
            ];
        }

        foreach (DevelopmentAction::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('employee_entity_id', $employeeEntityId)
            ->where('skill_id', $skillId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $action) {
            $entries[] = [
                'kind' => 'development_action',
                'at' => $action->created_at->toIso8601String(),
                'id' => (int) $action->getKey(),
                'summary' => sprintf(
                    'Development action %s → %s (target %d)',
                    $action->type instanceof \BackedEnum ? $action->type->value : (string) $action->type,
                    $action->status instanceof \BackedEnum ? $action->status->value : (string) $action->status,
                    (int) $action->target_level,
                ),
                'meta' => [
                    'status' => $action->status instanceof \BackedEnum ? $action->status->value : (string) $action->status,
                    'target_level' => (int) $action->target_level,
                    'starting_level' => $action->starting_level,
                    'post_assessment_id' => $action->post_assessment_id,
                    'post_level' => $action->post_level,
                ],
            ];
        }

        foreach (ReassessmentRequest::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('employee_entity_id', $employeeEntityId)
            ->where('skill_id', $skillId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $request) {
            $entries[] = [
                'kind' => 'reassessment_request',
                'at' => $request->created_at->toIso8601String(),
                'id' => (int) $request->getKey(),
                'summary' => sprintf(
                    'Reassessment %s from %s (target %d, due %s)',
                    $request->status instanceof \BackedEnum ? $request->status->value : (string) $request->status,
                    $request->source instanceof \BackedEnum ? $request->source->value : (string) $request->source,
                    (int) $request->target_level,
                    $request->due_date->toDateString(),
                ),
                'meta' => [
                    'status' => $request->status instanceof \BackedEnum ? $request->status->value : (string) $request->status,
                    'source' => $request->source instanceof \BackedEnum ? $request->source->value : (string) $request->source,
                    'before_level' => $request->before_level,
                    'achieved' => $request->achieved,
                    'fulfilled_assessment_id' => $request->fulfilled_assessment_id,
                    'source_training_event_id' => $request->source_training_event_id,
                    'source_development_action_id' => $request->source_development_action_id,
                ],
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['at'], $a['kind'], $a['id']] <=> [$b['at'], $b['kind'], $b['id']]);

        return $entries;
    }
}
