<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
        $this->authorizeManage();
        $this->selectedAssessmentIds = in_array($assessmentId, $this->selectedAssessmentIds, true)
            ? array_values(array_filter($this->selectedAssessmentIds, fn (int $id): bool => $id !== $assessmentId))
            : [...$this->selectedAssessmentIds, $assessmentId];
    }

    public function propose(DevelopmentActionStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $first = SkillAssessment::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereIn('id', $this->selectedAssessmentIds)
            ->first();
        if ($first === null) {
            $this->addError('actions', __('Select at least one current gap.'));

            return;
        }

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
        } catch (\Throwable $exception) {
            $this->addError('actions', $exception instanceof InvalidDevelopmentActionException
                ? $exception->getMessage() : __('Check the action type and dates.'));

            return;
        }

        $this->reset('selectedAssessmentIds', 'objective', 'intervention', 'expectedEvidence');
        session()->flash('status', __('Development action proposals created. Tailor and approve each commitment before work starts.'));
    }

    public function approve(int $actionId, DevelopmentActionStore $store): void
    {
        $store->approve($this->authorizedCompanyForManage(), $actionId, (int) Auth::id());
    }

    public function tailor(int $actionId, DevelopmentActionStore $store): void
    {
        $companyId = $this->authorizedCompanyForManage();
        $action = $store->operationalQuery($companyId)->whereKey($actionId)->firstOrFail();
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
        } catch (\Throwable $exception) {
            $this->addError('actions', $exception instanceof InvalidDevelopmentActionException
                ? $exception->getMessage() : __('Check the tailored action fields.'));
        }
    }

    public function start(int $actionId, DevelopmentActionStore $store): void
    {
        $store->start($this->authorizedCompanyForManage(), $actionId, (int) Auth::id());
    }

    public function complete(int $actionId, DevelopmentActionStore $store): void
    {
        $reassessmentDue = trim((string) ($this->reassessmentDue[$actionId] ?? ''));
        if ($reassessmentDue === '') {
            $this->addError('actions', __('Choose a reassessment date before completing the intervention.'));

            return;
        }

        try {
            $store->completeIntervention($this->authorizedCompanyForManage(), $actionId,
                (string) ($this->completionEvidence[$actionId] ?? ''),
                new \DateTimeImmutable($reassessmentDue), (int) Auth::id());
            unset($this->completionEvidence[$actionId], $this->reassessmentDue[$actionId]);
        } catch (\Throwable $exception) {
            $this->addError('actions', $exception instanceof InvalidDevelopmentActionException
                ? $exception->getMessage() : __('Choose a reassessment date.'));
        }
    }

    public function cancel(int $actionId, DevelopmentActionStore $store): void
    {
        try {
            $store->cancel($this->authorizedCompanyForManage(), $actionId, (string) ($this->reason[$actionId] ?? ''), (int) Auth::id());
            unset($this->reason[$actionId]);
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('actions', $exception->getMessage());
        }
    }

    public function verifyReassessment(int $actionId, DevelopmentActionStore $store): void
    {
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
        $skillNames = collect();
        $history = collect();
        $eligibleReassessments = collect();
        if ($companyId !== null && array_key_exists($companyId, $companies)) {
            $employees = WorkforceEmployeeProjection::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->where('active', true)->orderBy('display_name')->get();
            $gapSourceIds = EmployeeSkillScore::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->where('gap', '>', 0)
                ->pluck('source_assessment_id');
            $expiredCriticalSourceIds = EmployeeSkillScore::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
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
                ->whereIn('id', $sourceIds)->whereNotIn('id', $openSourceIds)
                ->orderByDesc('mandatory_gate')->orderByDesc('priority_score')->get();
            $actions = $store->operationalQuery($companyId)->get();
            $skillNames = Skill::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('id', $gaps->pluck('skill_id')->merge($actions->pluck('skill_id'))->unique())
                ->pluck('name', 'id');
            $history = DevelopmentActionAuditEvent::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
                ->whereIn('development_action_id', $actions->pluck('id'))
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
        return $this->allowedCompanies ??= app(CompanyAttribution::class)->allowedCompanyEntities(Auth::user());
    }

    private function canManage(): bool
    {
        return app(AuthorizationService::class)->can(Actor::forUser(Auth::user()),
            'people-connector.skill.development-action.manage')->allowed;
    }

    private function authorizeView(): void
    {
        app(AuthorizationService::class)->authorize(Actor::forUser(Auth::user()),
            'people-connector.skill.development-action.view');
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(Actor::forUser(Auth::user()),
            'people-connector.skill.development-action.manage');
    }

    private function authorizedCompanyForManage(): int
    {
        $this->authorizeManage();
        abort_unless($this->companyEntityId !== null && array_key_exists($this->companyEntityId, $this->allowedCompanies()), 404);

        return (int) $this->companyEntityId;
    }
}
