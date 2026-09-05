<?php

namespace App\Domains\PeopleConnector\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Training\Data\TrainingRequestDraft;
use App\Domains\PeopleConnector\Training\Enums\TrainingRequestStatus;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingRequest;
use App\Domains\PeopleConnector\Training\Models\TrainingRequestDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Company-scoped approval aggregate. It deliberately does not create participant truth. */
final class TrainingRequestStore
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly TrainingAudience $audience) {}

    public function create(User $actor, int $companyEntityId, TrainingRequestDraft $draft): TrainingRequest
    {
        $this->audience->authorizeRequest($actor, TrainingAudience::REQUEST_SUBMIT, $companyEntityId, SkillAudience::HR);

        return DB::transaction(function () use ($actor, $companyEntityId, $draft): TrainingRequest {
            $this->validateDraft($companyEntityId, $draft);
            $request = TrainingRequest::query()->create([
                'tenant_id' => $this->tenantContext->requireTenantId(), 'company_entity_id' => $companyEntityId, 'request_key' => (string) Str::uuid(),
                'title' => trim($draft->title), 'business_need' => trim($draft->businessNeed), 'requester_employee_entity_id' => $draft->requesterEmployeeEntityId,
                'department_entity_id' => $draft->departmentEntityId, 'course_id' => $draft->courseId, 'skill_reference' => $this->nullable($draft->skillReference),
                'development_action_reference' => $this->nullable($draft->developmentActionReference), 'proposed_budget_minor' => $draft->proposedBudgetMinor,
                'currency' => $draft->proposedBudgetMinor === null ? null : strtoupper((string) $draft->currency), 'status' => TrainingRequestStatus::Draft, 'created_by_user_id' => $this->actorId($actor),
            ]);
            $this->record($request, 'created', $actor);

            return $request;
        });
    }

    public function submit(User $actor, int $companyEntityId, int $requestId): TrainingRequest
    {
        return $this->move($actor, $companyEntityId, $requestId, TrainingRequestStatus::Draft, TrainingRequestStatus::PendingHod, 'submitted', TrainingAudience::REQUEST_SUBMIT, SkillAudience::HR);
    }

    public function hodEndorse(User $actor, int $companyEntityId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyEntityId, $requestId, TrainingRequestStatus::PendingHod, TrainingRequestStatus::PendingHr, 'hod_endorsed', TrainingAudience::REQUEST_HOD_REVIEW, SkillAudience::HOD, $notes);
    }

    public function hrEndorse(User $actor, int $companyEntityId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyEntityId, $requestId, TrainingRequestStatus::PendingHr, TrainingRequestStatus::PendingApproval, 'hr_endorsed', TrainingAudience::REQUEST_HR_REVIEW, SkillAudience::HR, $notes);
    }

    public function approve(User $actor, int $companyEntityId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyEntityId, $requestId, TrainingRequestStatus::PendingApproval, TrainingRequestStatus::Approved, 'approved', TrainingAudience::REQUEST_APPROVE, SkillAudience::HR, $notes);
    }

    public function reject(User $actor, int $companyEntityId, int $requestId, string $notes): TrainingRequest
    {
        $this->text($notes, 'A rejection reason is required.');

        return $this->terminal($actor, $companyEntityId, $requestId, TrainingRequestStatus::Rejected, 'rejected', $notes);
    }

    public function cancel(User $actor, int $companyEntityId, int $requestId, string $notes): TrainingRequest
    {
        $this->text($notes, 'A cancellation reason is required.');

        return $this->terminal($actor, $companyEntityId, $requestId, TrainingRequestStatus::Cancelled, 'cancelled', $notes);
    }

    private function move(User $actor, int $company, int $id, TrainingRequestStatus $from, TrainingRequestStatus $to, string $decision, string $capability, string $role, ?string $notes = null): TrainingRequest
    {
        $this->audience->authorizeRequest($actor, $capability, $company, $role);

        return DB::transaction(function () use ($actor, $company, $id, $from, $to, $decision, $notes): TrainingRequest {
            $request = $this->find($company, $id, true);
            if ($request->status !== $from) {
                throw new InvalidTrainingRequestException("Request is not awaiting {$from->value}.");
            } $request->update(['status' => $to]);
            $this->record($request, $decision, $actor, $notes);

            return $request->refresh();
        });
    }

    private function terminal(User $actor, int $company, int $id, TrainingRequestStatus $to, string $decision, string $notes): TrainingRequest
    {
        $this->audience->authorizeRequest($actor, TrainingAudience::REQUEST_HR_REVIEW, $company, SkillAudience::HR);

        return DB::transaction(function () use ($actor, $company, $id, $to, $decision, $notes): TrainingRequest {
            $request = $this->find($company, $id, true);
            if (in_array($request->status, [TrainingRequestStatus::Approved, TrainingRequestStatus::Rejected, TrainingRequestStatus::Cancelled], true)) {
                throw new InvalidTrainingRequestException('A terminal training request cannot be changed.');
            } $request->update(['status' => $to]);
            $this->record($request, $decision, $actor, $notes);

            return $request->refresh();
        });
    }

    private function validateDraft(int $company, TrainingRequestDraft $draft): void
    {
        $this->text($draft->title, 'A request title is required.');
        $this->text($draft->businessNeed, 'A business need is required.');
        $tenant = $this->tenantContext->requireTenantId();
        WorkforceEmployeeProjection::query()->forCompany($tenant, $company)->where('active', true)->where('workforce_entity_id', $draft->requesterEmployeeEntityId)->first() ?? throw new InvalidTrainingRequestException('Choose an active requester from this company.');
        if ($draft->departmentEntityId !== null) {
            WorkforceOrganizationUnitProjection::query()->forCompany($tenant, $company)->where('active', true)->where('workforce_entity_id', $draft->departmentEntityId)->first() ?? throw new InvalidTrainingRequestException('Choose an active department from this company.');
        }
        if ($draft->courseId !== null) {
            TrainingCourse::query()->forCompany($tenant, $company)->where('active', true)->find($draft->courseId) ?? throw new InvalidTrainingRequestException('Choose an active course from this company.');
        }
        if ($draft->proposedBudgetMinor !== null && ($draft->proposedBudgetMinor < 0 || preg_match('/^[A-Z]{3}$/', strtoupper((string) $draft->currency)) !== 1)) {
            throw new InvalidTrainingRequestException('A non-negative budget requires an ISO currency.');
        }
    }

    private function find(int $company, int $id, bool $lock = false): TrainingRequest
    {
        $q = TrainingRequest::query()->forCompany($this->tenantContext->requireTenantId(), $company)->whereKey($id);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? throw new InvalidTrainingRequestException('Training request was not found in this company.');
    }

    private function record(TrainingRequest $request, string $decision, User $actor, ?string $notes = null): void
    {
        TrainingRequestDecision::query()->create(['tenant_id' => $request->tenant_id, 'company_entity_id' => $request->company_entity_id, 'training_request_id' => $request->id, 'decision' => $decision, 'actor_user_id' => $this->actorId($actor), 'notes' => $this->nullable($notes), 'occurred_at' => now()]);
    }

    private function actorId(User $actor): int
    {
        $id = $actor->getAuthIdentifier();
        if (! is_numeric($id) || (int) $id < 1) {
            throw new InvalidTrainingRequestException('Training requests require a persisted authenticated actor.');
        }

        return (int) $id;
    }

    private function text(string $text, string $message): void
    {
        if (trim($text) === '') {
            throw new InvalidTrainingRequestException($message);
        }
    }

    private function nullable(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
