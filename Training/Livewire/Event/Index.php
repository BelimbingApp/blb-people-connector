<?php

namespace App\Domains\PeopleConnector\Training\Livewire\Event;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\PeopleConnector\Training\Data\TrainingEventDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingEventAuditEvent;
use App\Domains\PeopleConnector\Training\Services\TrainingAudience;
use App\Domains\PeopleConnector\Training\Services\TrainingEventStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class Index extends Component
{
    public ?int $companyEntityId = null;

    public ?int $editingEventId = null;

    public ?int $courseId = null;

    public ?int $targetDepartmentEntityId = null;

    public ?int $organizerEmployeeEntityId = null;

    public ?int $internalTrainerEmployeeEntityId = null;

    public string $deliveryMode = '';

    public string $externalTrainerReference = '';

    public string $externalTrainerName = '';

    public string $venue = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public int $capacity = 1;

    /** @var array<int, string> */
    public array $evidence = [];

    /** @var array<int, string> */
    public array $reason = [];

    /** @var array<int, string> */
    public array $comment = [];

    /** @var array<int, string>|null */
    private ?array $companies = null;

    public function mount(TrainingAudience $audience): void
    {
        $companies = $this->allowedCompanies($audience);
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        $this->startsAt = now()->addWeek()->startOfHour()->format('Y-m-d\TH:i');
        $this->endsAt = now()->addWeek()->addHours(2)->startOfHour()->format('Y-m-d\TH:i');
    }

    public function selectCompany(int $companyEntityId, TrainingAudience $audience): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies($audience)), 404);
        $this->companyEntityId = $companyEntityId;
        $this->resetForm();
    }

    public function editEvent(int $eventId, TrainingAudience $audience): void
    {
        $company = $this->managedCompany($audience);
        $event = $audience->visibleEvents(Auth::user(), $company)->whereKey($eventId)->firstOrFail();
        abort_unless($event->status === TrainingEventStatus::Scheduled, 409);
        $this->editingEventId = (int) $event->id;
        $this->courseId = (int) $event->course_id;
        $this->targetDepartmentEntityId = $event->target_department_entity_id === null ? null : (int) $event->target_department_entity_id;
        $this->organizerEmployeeEntityId = (int) $event->organizer_employee_entity_id;
        $this->internalTrainerEmployeeEntityId = $event->internal_trainer_employee_entity_id === null ? null : (int) $event->internal_trainer_employee_entity_id;
        $this->deliveryMode = $event->delivery_mode_snapshot->value;
        $this->externalTrainerReference = (string) $event->external_trainer_reference;
        $this->externalTrainerName = (string) $event->external_trainer_name_snapshot;
        $this->venue = (string) $event->venue;
        $this->startsAt = $event->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $event->ends_at->format('Y-m-d\TH:i');
        $this->capacity = (int) $event->capacity;
    }

    public function save(TrainingAudience $audience, TrainingEventStore $store): void
    {
        $company = $this->managedCompany($audience);
        $validated = $this->validate([
            'courseId' => ['required', 'integer'],
            'targetDepartmentEntityId' => ['nullable', 'integer'],
            'organizerEmployeeEntityId' => ['required', 'integer'],
            'internalTrainerEmployeeEntityId' => ['nullable', 'integer'],
            'deliveryMode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'externalTrainerReference' => ['nullable', 'string', 'max:160'],
            'externalTrainerName' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $draft = new TrainingEventDraft(
            courseId: (int) $validated['courseId'],
            startsAt: new \DateTimeImmutable($validated['startsAt']),
            endsAt: new \DateTimeImmutable($validated['endsAt']),
            capacity: (int) $validated['capacity'],
            organizerEmployeeEntityId: (int) $validated['organizerEmployeeEntityId'],
            targetDepartmentEntityId: $this->targetDepartmentEntityId,
            deliveryMode: $this->deliveryMode === '' ? null : DeliveryMode::from($this->deliveryMode),
            venue: $this->venue,
            internalTrainerEmployeeEntityId: $this->internalTrainerEmployeeEntityId,
            externalTrainerReference: $this->externalTrainerReference,
            externalTrainerName: $this->externalTrainerName,
        );

        try {
            if ($this->editingEventId === null) {
                $store->schedule($company, $draft, (int) Auth::id(), $this->actorEmployeeId());
            } else {
                $store->revise($company, $this->editingEventId, $draft, (int) Auth::id(), $this->actorEmployeeId());
            }
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());

            return;
        }

        $this->resetForm();
        session()->flash('status', __('Training event saved.'));
    }

    public function start(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->start($this->managedCompany($audience), $eventId, (int) Auth::id(), $this->actorEmployeeId());
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function complete(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->complete($this->managedCompany($audience), $eventId, (string) ($this->evidence[$eventId] ?? ''),
                (int) Auth::id(), $this->actorEmployeeId());
            unset($this->evidence[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function cancel(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->cancel($this->managedCompany($audience), $eventId, (string) ($this->reason[$eventId] ?? ''),
                (int) Auth::id(), $this->actorEmployeeId());
            unset($this->reason[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function addComment(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->comment($this->managedCompany($audience), $eventId, (string) ($this->comment[$eventId] ?? ''),
                actorUserId: (int) Auth::id(), actorEmployeeEntityId: $this->actorEmployeeId());
            unset($this->comment[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function render(TrainingAudience $audience, SummarizesTrainingParticipation $participation): View
    {
        $companies = $this->allowedCompanies($audience);
        $company = $this->companyEntityId;
        $events = collect();
        $courses = collect();
        $departments = collect();
        $employees = collect();
        $history = collect();
        $summaries = [];
        $canManage = false;

        if ($company !== null && array_key_exists($company, $companies)) {
            $events = $audience->visibleEvents(Auth::user(), $company)->orderByDesc('starts_at')->get();
            $canManage = $audience->canManage(Auth::user(), $company);
            $tenant = app(TenantContext::class)->requireTenantId();
            if ($canManage) {
                $courses = TrainingCourse::query()->forCompany($tenant, $company)->where('active', true)->orderBy('title')->get();
                $departments = WorkforceOrganizationUnitProjection::query()->forCompany($tenant, $company)->where('active', true)->orderBy('name')->get();
                $employees = WorkforceEmployeeProjection::query()->forCompany($tenant, $company)->where('active', true)->orderBy('display_name')->get();
            } else {
                $departments = WorkforceOrganizationUnitProjection::query()->forCompany($tenant, $company)
                    ->whereIn('workforce_entity_id', $events->pluck('target_department_entity_id')->filter()->unique())
                    ->get();
                $employees = WorkforceEmployeeProjection::query()->forCompany($tenant, $company)
                    ->whereIn('workforce_entity_id', $events->pluck('organizer_employee_entity_id')
                        ->merge($events->pluck('internal_trainer_employee_entity_id'))->filter()->unique())
                    ->get();
            }
            $history = TrainingEventAuditEvent::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $company)
                ->whereIn('training_event_id', $events->pluck('id'))
                ->orderByDesc('occurred_at')->get()->groupBy('training_event_id');
            $summaries = $participation->forEvents($company, $events->pluck('id')->map(intval(...))->all());
        }

        return view('people-connector-training::livewire.event.index', compact(
            'companies', 'events', 'courses', 'departments', 'employees', 'history', 'summaries', 'canManage',
        ));
    }

    /** @return array<int, string> */
    private function allowedCompanies(TrainingAudience $audience): array
    {
        return $this->companies ??= $audience->allowedCompanies(Auth::user());
    }

    private function managedCompany(TrainingAudience $audience): int
    {
        abort_unless($this->companyEntityId !== null, 404);
        $audience->authorizeManage(Auth::user(), $this->companyEntityId);

        return $this->companyEntityId;
    }

    private function actorEmployeeId(): ?int
    {
        return $this->companyEntityId === null ? null
            : app(SkillAudience::class)->boundEmployeeEntityId(Auth::user(), $this->companyEntityId);
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingEventId', 'courseId', 'targetDepartmentEntityId', 'organizerEmployeeEntityId',
            'internalTrainerEmployeeEntityId', 'deliveryMode', 'externalTrainerReference', 'externalTrainerName', 'venue');
        $this->capacity = 1;
    }
}
