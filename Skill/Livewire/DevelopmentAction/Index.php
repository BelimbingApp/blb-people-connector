<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Skill\Data\DevelopmentActionDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus;
use App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionType;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidDevelopmentActionException;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentAction;
use App\Domains\PeopleConnector\Skill\Models\DevelopmentActionAuditEvent;
use App\Domains\PeopleConnector\Skill\Models\EmployeeSkillScore;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessment;
use App\Domains\PeopleConnector\Skill\Services\DevelopmentActionStore;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class Index extends Component
{
    public ?int $companyEntityId = null;

    /** @var list<int> */
    public array $selectedAssessmentIds = [];

    public string $actionType = 'coaching';

    public string $objective = '';

    public string $intervention = '';

    public string $expectedEvidence = '';

    public ?int $ownerEmployeeEntityId = null;

    public ?int $hrCoordinatorEmployeeEntityId = null;

    public ?int $trainerEmployeeEntityId = null;

    public string $trainerProviderName = '';

    public string $startDate = '';

    public string $dueDate = '';

    /** @var array<int, string> */
    public array $completionEvidence = [];

    /** @var array<int, string> */
    public array $reassessmentDue = [];

    /** @var array<int, int|string> */
    public array $postAssessmentId = [];

    /** @var array<int, string> */
    public array $reason = [];

    /** @var array<int, string> */
    public array $actionComment = [];

    /** @var array<int, string> */
    public array $actionEvidence = [];

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        $this->startDate = now()->toDateString();
        $this->dueDate = now()->addWeekdays(10)->toDateString();
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('selectedAssessmentIds');
    }

    public function toggleAssessment(int $assessmentId): void
    {
        $this->authorizedAssessment($assessmentId);
        $this->selectedAssessmentIds = in_array($assessmentId, $this->selectedAssessmentIds, true)
            ? array_values(array_filter($this->selectedAssessmentIds, fn (int $id): bool => $id !== $assessmentId))
            : [...$this->selectedAssessmentIds, $assessmentId];
    }

    public function propose(DevelopmentActionStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $this->validateDraftInput();
        $this->authorizeParticipants();
        $assessments = SkillAssessment::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereIn('id', $this->selectedAssessmentIds)
            ->whereIn('employee_entity_id', $this->visibleEmployeeIds(manage: true))
            ->get();
        if ($assessments->isEmpty()) {
            $this->addError('actions', __('Select at least one current gap.'));

            return;
        }
        abort_unless($assessments->count() === count(array_unique($this->selectedAssessmentIds)), 404);
        $first = $assessments->first();

        try {
            $store->proposeFromAssessments($companyEntityId, $this->selectedAssessmentIds, new DevelopmentActionDraft(
                employeeEntityId: (int) $first->employee_entity_id,
                type: DevelopmentActionType::from($this->actionType),
                objective: $this->objective,
                intervention: $this->intervention,
                expectedEvidence: $this->expectedEvidence,
                ownerEmployeeEntityId: (int) $this->ownerEmployeeEntityId,
                hrCoordinatorEmployeeEntityId: (int) $this->hrCoordinatorEmployeeEntityId,
                startDate: new \DateTimeImmutable($this->startDate),
                dueDate: new \DateTimeImmutable($this->dueDate),
                trainerEmployeeEntityId: $this->trainerEmployeeEntityId,
                trainerProviderName: $this->trainerProviderName,
            ), actorUserId: (int) Auth::id());
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());

            return;
        }

        $this->reset('selectedAssessmentIds', 'objective', 'intervention', 'expectedEvidence');
        session()->flash('status', __('Development action proposals created. Tailor and approve each commitment before work starts.'));
    }

    public function approve(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        $store->approve($this->authorizedCompanyForManage(), $actionId, (int) Auth::id());
    }

    public function tailor(int $actionId, DevelopmentActionStore $store): void
    {
        $this->validateDraftInput();
        $this->authorizeParticipants();
        $companyId = $this->authorizedCompanyForManage();
        $action = $this->authorizedAction($actionId);
        try {
            $store->reviseProposal($companyId, $actionId, new DevelopmentActionDraft(
                employeeEntityId: (int) $action->employee_entity_id,
                type: DevelopmentActionType::from($this->actionType),
                objective: $this->objective,
                intervention: $this->intervention,
                expectedEvidence: $this->expectedEvidence,
                ownerEmployeeEntityId: (int) $this->ownerEmployeeEntityId,
                hrCoordinatorEmployeeEntityId: (int) $this->hrCoordinatorEmployeeEntityId,
                startDate: new \DateTimeImmutable($this->startDate),
                dueDate: new \DateTimeImmutable($this->dueDate),
                trainerEmployeeEntityId: $this->trainerEmployeeEntityId,
                trainerProviderName: $this->trainerProviderName,
                skillId: (int) $action->skill_id,
                startingLevel: (int) $action->starting_level,
                targetLevel: (int) $action->target_level,
                criticality: $action->criticality,
                mandatoryGate: (bool) $action->mandatory_gate,
            ), (int) Auth::id());
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function start(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        $store->start($this->authorizedCompanyForManage(), $actionId, (int) Auth::id());
    }

    public function complete(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        $this->validate([
            "reassessmentDue.{$actionId}" => ['required', 'date', 'after_or_equal:today'],
        ]);
        $reassessmentDue = trim((string) $this->reassessmentDue[$actionId]);

        try {
            $store->completeIntervention($this->authorizedCompanyForManage(), $actionId,
                (string) ($this->completionEvidence[$actionId] ?? ''),
                new \DateTimeImmutable($reassessmentDue), (int) Auth::id());
            unset($this->completionEvidence[$actionId], $this->reassessmentDue[$actionId]);
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function cancel(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        try {
            $store->cancel($this->authorizedCompanyForManage(), $actionId, (string) ($this->reason[$actionId] ?? ''), (int) Auth::id());
            unset($this->reason[$actionId]);
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function verifyReassessment(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        $assessmentId = (int) ($this->postAssessmentId[$actionId] ?? 0);
        if ($assessmentId <= 0) {
            $this->addError('actions', __('Choose a finalized post-training reassessment.'));

            return;
        }

        try {
            $store->linkReassessment($this->authorizedCompanyForManage(), $actionId, $assessmentId, (int) Auth::id());
            unset($this->postAssessmentId[$actionId]);
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function addComment(int $actionId, DevelopmentActionStore $store): void
    {
        $this->authorizedAction($actionId);
        try {
            $store->comment($this->authorizedCompanyForManage(), $actionId,
                (string) ($this->actionComment[$actionId] ?? ''),
                $this->actionEvidence[$actionId] ?? null, (int) Auth::id());
            unset($this->actionComment[$actionId], $this->actionEvidence[$actionId]);
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function render(DevelopmentActionStore $store): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyId = $this->companyEntityId;
        $employees = collect();
        $gaps = collect();
        $actions = collect();
        $terminalActions = collect();
        $skillNames = collect();
        $history = collect();
        $eligibleReassessments = collect();
        if ($companyId !== null && array_key_exists($companyId, $companies)) {
            $visibleEmployeeIds = $this->visibleEmployeeIds(manage: false);
            $employees = WorkforceEmployeeProjection::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('workforce_entity_id', $visibleEmployeeIds)
                ->where('active', true)->orderBy('display_name')->get();
            $gapSourceIds = EmployeeSkillScore::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('employee_entity_id', $visibleEmployeeIds)
                ->where('gap', '>', 0)
                ->pluck('source_assessment_id');
            $expiredCriticalSourceIds = EmployeeSkillScore::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('employee_entity_id', $visibleEmployeeIds)
                ->where('criticality', 'critical')
                ->whereDate('valid_until', '<', today())
                ->pluck('source_assessment_id');
            $sourceIds = $gapSourceIds->merge($expiredCriticalSourceIds)->unique();
            $openSourceIds = DevelopmentAction::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotNull('source_assessment_id')
                ->pluck('source_assessment_id');
            $gaps = SkillAssessment::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('employee_entity_id', $visibleEmployeeIds)
                ->whereIn('id', $sourceIds)->whereNotIn('id', $openSourceIds)
                ->orderByDesc('mandatory_gate')->orderByDesc('priority_score')->get();
            $actions = $store->operationalQuery($companyId)->whereIn('employee_entity_id', $visibleEmployeeIds)->get();
            $terminalActions = $store->terminalQuery($companyId)->whereIn('employee_entity_id', $visibleEmployeeIds)->get();
            $skillNames = Skill::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('id', $gaps->pluck('skill_id')->merge($actions->pluck('skill_id'))->merge($terminalActions->pluck('skill_id'))->unique())
                ->pluck('name', 'id');
            $actionIds = $actions->pluck('id')->merge($terminalActions->pluck('id'));
            $history = DevelopmentActionAuditEvent::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('development_action_id', $actionIds)
                ->orderByDesc('occurred_at')->get()->groupBy('development_action_id');
            $pending = $actions->where('status', DevelopmentActionStatus::PendingReassessment);
            if ($pending->isNotEmpty()) {
                $postAssessments = SkillAssessment::query()
                    ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                    ->where('status', AssessmentStatus::Finalized->value)
                    ->where('cycle', AssessmentCycle::PostTraining->value)
                    ->whereIn('employee_entity_id', $pending->pluck('employee_entity_id')->unique())
                    ->whereIn('skill_id', $pending->pluck('skill_id')->unique())
                    ->orderByDesc('assessed_at')
                    ->get();
                $eligibleReassessments = $pending->mapWithKeys(fn (DevelopmentAction $action): array => [
                    $action->id => $postAssessments->filter(fn (SkillAssessment $assessment): bool => (int) $assessment->employee_entity_id === (int) $action->employee_entity_id
                        && (int) $assessment->skill_id === (int) $action->skill_id
                        && ! $assessment->assessed_at->lessThan($action->completed_at)),
                ]);
            }
        }

        return view('people-connector-skill::livewire.development-action.index', [
            'companies' => $companies,
            'employees' => $employees,
            'gaps' => $gaps,
            'actions' => $actions,
            'terminalActions' => $terminalActions,
            'employeeNames' => $employees->pluck('display_name', 'workforce_entity_id'),
            'skillNames' => $skillNames,
            'history' => $history,
            'eligibleReassessments' => $eligibleReassessments,
            'canManage' => $this->canManage(),
        ]);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(
            $this->user(),
            'people-connector.skill.development-action.view',
        );
    }

    private function canManage(): bool
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                $this->user(),
                'people-connector.skill.development-action.manage',
            );

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience($this->user(),
                'people-connector.skill.development-action.view');
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience($this->user(),
                'people-connector.skill.development-action.manage');
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizedCompanyForManage(): int
    {
        $this->authorizeManage();
        abort_unless($this->companyEntityId !== null && array_key_exists($this->companyEntityId, $this->allowedCompanies()), 404);

        return (int) $this->companyEntityId;
    }

    /** @return list<int> */
    private function visibleEmployeeIds(bool $manage): array
    {
        $companyEntityId = $manage ? $this->authorizedCompanyForManage() : (int) $this->companyEntityId;

        return app(SkillAudience::class)->visibleDevelopmentActionEmployeeEntityIds(
            $this->user(),
            $companyEntityId,
            $manage,
        );
    }

    private function authorizedAssessment(int $assessmentId): SkillAssessment
    {
        return SkillAssessment::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $this->authorizedCompanyForManage())
            ->whereIn('employee_entity_id', $this->visibleEmployeeIds(manage: true))
            ->whereKey($assessmentId)
            ->firstOrFail();
    }

    private function authorizedAction(int $actionId): DevelopmentAction
    {
        return DevelopmentAction::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $this->authorizedCompanyForManage())
            ->whereIn('employee_entity_id', $this->visibleEmployeeIds(manage: true))
            ->whereKey($actionId)
            ->firstOrFail();
    }

    private function validateDraftInput(): void
    {
        $this->validate([
            'actionType' => ['required', Rule::enum(DevelopmentActionType::class)],
            'startDate' => ['required', 'date'],
            'dueDate' => ['required', 'date', 'after_or_equal:startDate'],
        ]);
    }

    private function authorizeParticipants(): void
    {
        $visible = $this->visibleEmployeeIds(manage: true);
        foreach ([$this->ownerEmployeeEntityId, $this->hrCoordinatorEmployeeEntityId, $this->trainerEmployeeEntityId] as $employeeId) {
            if ($employeeId !== null) {
                abort_unless(in_array($employeeId, $visible, true), 404);
            }
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
