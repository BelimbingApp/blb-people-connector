<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\Reassessment;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Skill\Data\ReassessmentRequestDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestSource;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidReassessmentRequestException;
use App\Domains\PeopleConnector\Skill\Models\ReassessmentRequest;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\ReassessmentRequestStore;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class Index extends Component
{
    public ?int $companyEntityId = null;

    public ?int $employeeEntityId = null;

    public ?int $skillId = null;

    public int $targetLevel = 4;

    public string $dueDate = '';

    public string $requiredEvidence = '';

    public string $notes = '';

    /** @var array<int, string> */
    public array $cancelReason = [];

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        $this->dueDate = now()->addDays(14)->toDateString();
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('employeeEntityId', 'skillId', 'cancelReason');
    }

    public function openManual(ReassessmentRequestStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $this->validate([
            'employeeEntityId' => ['required', 'integer'],
            'skillId' => ['required', 'integer'],
            'targetLevel' => ['required', 'integer', 'min:0', 'max:5'],
            'dueDate' => ['required', 'date', 'after_or_equal:today'],
            'requiredEvidence' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $visible = $this->visibleEmployeeIds(manage: true);
        abort_unless(in_array((int) $this->employeeEntityId, $visible, true), 404);

        try {
            $store->request($companyEntityId, new ReassessmentRequestDraft(
                employeeEntityId: (int) $this->employeeEntityId,
                skillId: (int) $this->skillId,
                dueDate: $this->dueDate,
                cycle: AssessmentCycle::PostTraining,
                source: ReassessmentRequestSource::Manual,
                targetLevel: $this->targetLevel,
                assignedEvaluatorUserId: $this->user()->id,
                requiredEvidence: $this->requiredEvidence !== '' ? $this->requiredEvidence : null,
                notes: $this->notes !== '' ? $this->notes : 'Opened by an authorized evaluator.',
            ), createdByUserId: $this->user()->id);
        } catch (InvalidReassessmentRequestException $e) {
            $this->addError('request', $e->getMessage());

            return;
        }

        $this->reset('employeeEntityId', 'skillId', 'requiredEvidence', 'notes');
        $this->targetLevel = 4;
        $this->dueDate = now()->addDays(14)->toDateString();
        session()->flash('status', __('Reassessment request opened. Completing it still requires a finalized assessment.'));
    }

    public function cancel(int $requestId, ReassessmentRequestStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $this->validate([
            "cancelReason.{$requestId}" => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $this->authorizedRequest($requestId);

        try {
            $store->cancel($companyEntityId, $requestId, $this->cancelReason[$requestId]);
        } catch (InvalidReassessmentRequestException $e) {
            $this->addError('request', $e->getMessage());

            return;
        }

        unset($this->cancelReason[$requestId]);
        session()->flash('status', __('Reassessment request cancelled.'));
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $employees = collect();
        $skills = collect();
        $requests = collect();
        $employeeNames = [];
        $skillNames = [];

        if ($this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)) {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $visible = $this->visibleEmployeeIds(manage: false);
            $employees = WorkforceEmployeeProjection::query()
                ->forCompany($tenantId, $this->companyEntityId)
                ->whereIn('workforce_entity_id', $visible)
                ->where('active', true)
                ->orderBy('display_name')
                ->get();
            $skills = Skill::query()
                ->forCompany($tenantId, $this->companyEntityId)
                ->where('active', true)
                ->orderBy('name')
                ->get();
            $requests = app(ReassessmentRequestStore::class)->openQuery($this->companyEntityId)
                ->whereIn('employee_entity_id', $visible)
                ->with([])
                ->get();
            $employeeNames = $employees->pluck('display_name', 'workforce_entity_id')->all();
            $skillNames = $skills->pluck('name', 'id')->all();
        }

        return view('people-connector-skill::livewire.reassessment.index', [
            'companies' => $companies,
            'employees' => $employees,
            'skills' => $skills,
            'requests' => $requests,
            'employeeNames' => $employeeNames,
            'skillNames' => $skillNames,
            'canManage' => $this->canManage(),
        ]);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(
            $this->user(),
            'people-connector.skill.reassessment.view',
        );
    }

    private function canManage(): bool
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                $this->user(),
                'people-connector.skill.reassessment.manage',
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
                $this->user(),
                'people-connector.skill.reassessment.view',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                $this->user(),
                'people-connector.skill.reassessment.manage',
            );
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

        return app(SkillAudience::class)->visibleReassessmentEmployeeEntityIds(
            $this->user(),
            $companyEntityId,
            $manage,
        );
    }

    private function authorizedRequest(int $requestId): ReassessmentRequest
    {
        return ReassessmentRequest::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $this->authorizedCompanyForManage())
            ->whereIn('employee_entity_id', $this->visibleEmployeeIds(manage: true))
            ->whereKey($requestId)
            ->firstOrFail();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
