<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\Assessment;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Data\AssessmentDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\AssessmentStore;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Assessment matrix — working surface that atomically submits evidence-backed
 * cells for independent HOD verification. Not a second source of truth.
 */
class Matrix extends Component
{
    public ?int $companyEntityId = null;

    /** @var list<int> */
    public array $selectedSkillIds = [];

    /** @var array<string, string> employeeId:skillId => level string */
    public array $scores = [];

    /** @var array<string, string> employeeId:skillId => evidence */
    public array $evidence = [];

    public string $cycle = 'annual';

    public string $method = 'direct_observation';

    public string $sharedEvidence = '';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('selectedSkillIds', 'scores', 'evidence');
    }

    public function toggleSkill(int $skillId): void
    {
        $this->authorizeAssess();
        if (in_array($skillId, $this->selectedSkillIds, true)) {
            $this->selectedSkillIds = array_values(array_filter(
                $this->selectedSkillIds,
                fn (int $id): bool => $id !== $skillId,
            ));
        } elseif (count($this->selectedSkillIds) < 12) {
            $this->selectedSkillIds[] = $skillId;
        }
    }

    public function saveMatrix(AssessmentStore $store, SkillAudience $audience): void
    {
        $companyEntityId = $this->authorizedCompanyForAssess();

        if ($this->selectedSkillIds === []) {
            $this->addError('matrix', __('Select at least one skill (up to 12).'));

            return;
        }

        $drafts = [];
        $defaultEvidence = trim($this->sharedEvidence);
        $assessedAt = now();

        foreach ($this->employees($companyEntityId) as $employee) {
            foreach ($this->selectedSkillIds as $skillId) {
                $key = $employee->workforce_entity_id.':'.$skillId;
                $levelRaw = trim((string) ($this->scores[$key] ?? ''));
                if ($levelRaw === '') {
                    continue;
                }

                if (! ctype_digit($levelRaw) || (int) $levelRaw > 5) {
                    $this->addError('matrix', __('Scores must be whole numbers from 0 to 5.'));

                    return;
                }

                $cellEvidence = trim((string) ($this->evidence[$key] ?? ''));
                if ($cellEvidence === '') {
                    $cellEvidence = $defaultEvidence;
                }

                $drafts[] = new AssessmentDraft(
                    employeeEntityId: (int) $employee->workforce_entity_id,
                    skillId: (int) $skillId,
                    assessedLevel: (int) $levelRaw,
                    method: AssessmentMethod::from($this->method),
                    cycle: AssessmentCycle::from($this->cycle),
                    assessedAt: $assessedAt,
                    evidence: $cellEvidence,
                    assessorUserId: (int) Auth::id(),
                );
            }
        }

        if ($drafts === []) {
            $this->addError('matrix', __('Enter at least one scored cell with evidence.'));

            return;
        }

        foreach ($drafts as $draft) {
            $audience->authorizeAssessmentSubmission(
                Auth::user(),
                $companyEntityId,
                $draft->employeeEntityId,
            );
        }

        try {
            $submitted = $store->submitBatch(
                $companyEntityId,
                $drafts,
            );
            $store->requestHodVerificationBatch(
                $companyEntityId,
                array_map(static fn (SkillAssessment $assessment): int => (int) $assessment->id, $submitted),
            );
        } catch (InvalidAssessmentException $exception) {
            $this->addError('matrix', $exception->getMessage());

            return;
        }

        $this->reset('scores', 'evidence');
        session()->flash('status', __('Assessment matrix submitted for HOD verification.'));
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId;
        $skills = collect();
        $employees = collect();
        $requiredLevels = [];

        if ($companyEntityId !== null && array_key_exists($companyEntityId, $companies)) {
            $skills = $this->skills($companyEntityId);
            $employees = $this->employees($companyEntityId);
            $requiredLevels = $this->requiredLevels($companyEntityId);
        }

        return view('people-connector-skill::livewire.assessment.matrix', [
            'companies' => $companies,
            'skills' => $skills,
            'employees' => $employees,
            'requiredLevels' => $requiredLevels,
            'canAssess' => $this->canAssess(),
            'selectedSkills' => $skills->whereIn('id', $this->selectedSkillIds)->values(),
        ]);
    }

    private function skills(int $companyEntityId)
    {
        return Skill::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->where('active', true)
            ->orderBy('code')
            ->get();
    }

    private function employees(int $companyEntityId)
    {
        $employeeEntityIds = app(SkillAudience::class)->visibleEmployeeEntityIds(
            Auth::user(),
            $companyEntityId,
            manage: $this->canAssess(),
        );

        return WorkforceEmployeeProjection::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereIn('workforce_entity_id', $employeeEntityIds)
            ->where('active', true)
            ->orderBy('display_name')
            ->limit(50)
            ->get();
    }

    /**
     * Required levels keyed by employeeEntityId:skillId from each employee's
     * workforce projection context (company + department/position).
     *
     * @return array<string, int>
     */
    private function requiredLevels(int $companyEntityId): array
    {
        $resolver = app(ResolvesSkillRequirements::class);
        $levels = [];

        foreach ($this->employees($companyEntityId) as $employee) {
            $context = [
                'company_entity_id' => $companyEntityId,
            ];
            if ($employee->organization_entity_id !== null) {
                $context['department_entity_id'] = (int) $employee->organization_entity_id;
            }
            if ($employee->position_entity_id !== null) {
                $context['position_entity_id'] = (int) $employee->position_entity_id;
            }

            foreach ($resolver->requirementsFor($context) as $requirement) {
                $levels[$employee->workforce_entity_id.':'.$requirement->skillId] = $requirement->requiredLevel;
            }
        }

        return $levels;
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(
            Auth::user(),
            'people-connector.skill.assessment.view',
        );
    }

    private function canAssess(): bool
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people-connector.skill.assessment.manage',
            );

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people-connector.skill.assessment.view',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeAssess(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people-connector.skill.assessment.manage',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizedCompanyForAssess(): int
    {
        $this->authorizeAssess();
        abort_unless(
            $this->companyEntityId !== null
            && array_key_exists($this->companyEntityId, $this->allowedCompanies()),
            404,
        );

        abort_if(
            app(SkillAudience::class)->visibleEmployeeEntityIds(
                Auth::user(),
                (int) $this->companyEntityId,
                manage: true,
            ) === [],
            403,
        );

        return (int) $this->companyEntityId;
    }
}
