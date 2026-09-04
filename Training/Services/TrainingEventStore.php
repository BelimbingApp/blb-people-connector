<?php

namespace App\Domains\PeopleConnector\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Training\Data\TrainingEventDraft;
use App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\PeopleConnector\Training\Exceptions\TrainingEventNotFoundException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingEvent;
use App\Domains\PeopleConnector\Training\Models\TrainingEventAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Connector-owned write boundary for scheduled training events. */
final class TrainingEventStore
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function schedule(
        int $companyEntityId,
        TrainingEventDraft $draft,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): TrainingEvent {
        return DB::transaction(function () use ($companyEntityId, $draft, $actorUserId, $actorEmployeeEntityId): TrainingEvent {
            $course = $this->validateDraft($companyEntityId, $draft);
            $trainerId = $draft->internalTrainerEmployeeEntityId ?? $course->internal_trainer_employee_entity_id;
            $event = TrainingEvent::query()->create([
                'tenant_id' => $this->tenantContext->requireTenantId(),
                'company_entity_id' => $companyEntityId,
                'event_key' => (string) Str::uuid(),
                'course_id' => $course->id,
                'course_code_snapshot' => $course->code,
                'course_title_snapshot' => $course->title,
                'delivery_mode_snapshot' => $draft->deliveryMode ?? $course->delivery_mode,
                'target_department_entity_id' => $draft->targetDepartmentEntityId,
                'organizer_employee_entity_id' => $draft->organizerEmployeeEntityId,
                'internal_trainer_employee_entity_id' => $trainerId,
                'external_trainer_reference' => $this->trimNullable($draft->externalTrainerReference),
                'external_trainer_name_snapshot' => $this->trimNullable($draft->externalTrainerName),
                'venue' => $this->trimNullable($draft->venue),
                'starts_at' => $draft->startsAt,
                'ends_at' => $draft->endsAt,
                'capacity' => $draft->capacity,
                'status' => TrainingEventStatus::Scheduled,
                'created_by_user_id' => $actorUserId,
            ]);
            $this->record($event, 'scheduled', null, TrainingEventStatus::Scheduled, null, null,
                $actorUserId, $actorEmployeeEntityId);

            return $event;
        });
    }

    public function revise(
        int $companyEntityId,
        int $eventId,
        TrainingEventDraft $draft,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): TrainingEvent {
        return DB::transaction(function () use ($companyEntityId, $eventId, $draft, $actorUserId, $actorEmployeeEntityId): TrainingEvent {
            $event = $this->find($companyEntityId, $eventId, true);
            if ($event->status !== TrainingEventStatus::Scheduled) {
                throw new InvalidTrainingEventException('Only a scheduled event can be revised.');
            }
            $course = $this->validateDraft($companyEntityId, $draft);
            $event->update([
                'course_id' => $course->id,
                'course_code_snapshot' => $course->code,
                'course_title_snapshot' => $course->title,
                'delivery_mode_snapshot' => $draft->deliveryMode ?? $course->delivery_mode,
                'target_department_entity_id' => $draft->targetDepartmentEntityId,
                'organizer_employee_entity_id' => $draft->organizerEmployeeEntityId,
                'internal_trainer_employee_entity_id' => $draft->internalTrainerEmployeeEntityId ?? $course->internal_trainer_employee_entity_id,
                'external_trainer_reference' => $this->trimNullable($draft->externalTrainerReference),
                'external_trainer_name_snapshot' => $this->trimNullable($draft->externalTrainerName),
                'venue' => $this->trimNullable($draft->venue),
                'starts_at' => $draft->startsAt,
                'ends_at' => $draft->endsAt,
                'capacity' => $draft->capacity,
            ]);
            $this->record($event, 'schedule_revised', TrainingEventStatus::Scheduled, TrainingEventStatus::Scheduled,
                null, null, $actorUserId, $actorEmployeeEntityId);

            return $event->refresh();
        });
    }

    public function start(int $companyEntityId, int $eventId, ?int $actorUserId = null, ?int $actorEmployeeEntityId = null): TrainingEvent
    {
        return $this->transition($companyEntityId, $eventId, TrainingEventStatus::Scheduled,
            TrainingEventStatus::InProgress, 'started', null, null, $actorUserId, $actorEmployeeEntityId);
    }

    public function complete(
        int $companyEntityId,
        int $eventId,
        string $evidence,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): TrainingEvent {
        $this->requireText($evidence, 'Completion evidence is required.');

        return $this->transition($companyEntityId, $eventId, TrainingEventStatus::InProgress,
            TrainingEventStatus::Completed, 'completed', null, trim($evidence), $actorUserId,
            $actorEmployeeEntityId, ['completed_at' => now(), 'completion_evidence' => trim($evidence)]);
    }

    public function cancel(
        int $companyEntityId,
        int $eventId,
        string $reason,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): TrainingEvent {
        $this->requireText($reason, 'A cancellation reason is required.');

        return DB::transaction(function () use ($companyEntityId, $eventId, $reason, $actorUserId, $actorEmployeeEntityId): TrainingEvent {
            $event = $this->find($companyEntityId, $eventId, true);
            if ($event->status->isTerminal()) {
                throw new InvalidTrainingEventException('A terminal training event cannot be cancelled.');
            }
            $from = $event->status;
            $event->update([
                'status' => TrainingEventStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ]);
            $this->record($event, 'cancelled', $from, TrainingEventStatus::Cancelled, trim($reason), null,
                $actorUserId, $actorEmployeeEntityId);

            return $event->refresh();
        });
    }

    public function comment(
        int $companyEntityId,
        int $eventId,
        string $comment,
        ?string $evidence = null,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): void {
        $this->requireText($comment, 'A comment is required.');
        $event = $this->find($companyEntityId, $eventId);
        $this->record($event, 'commented', null, null, trim($comment), $this->trimNullable($evidence),
            $actorUserId, $actorEmployeeEntityId);
    }

    public function registerQuery(int $companyEntityId): Builder
    {
        return TrainingEvent::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->orderByDesc('starts_at');
    }

    private function validateDraft(int $companyEntityId, TrainingEventDraft $draft): TrainingCourse
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $course = TrainingCourse::query()->forCompany($tenantId, $companyEntityId)
            ->where('active', true)->find($draft->courseId)
            ?? throw new InvalidTrainingEventException('Choose an active training course from this company.');
        if (CarbonImmutable::instance($draft->endsAt)->lessThanOrEqualTo(CarbonImmutable::instance($draft->startsAt))) {
            throw new InvalidTrainingEventException('The event end must be after its start.');
        }
        if ($draft->capacity < 1 || $draft->capacity > 1_000_000) {
            throw new InvalidTrainingEventException('Capacity must be between 1 and 1,000,000 participants.');
        }
        $this->employee($tenantId, $companyEntityId, $draft->organizerEmployeeEntityId, 'organizer');
        $trainerId = $draft->internalTrainerEmployeeEntityId ?? $course->internal_trainer_employee_entity_id;
        if ($trainerId !== null) {
            $this->employee($tenantId, $companyEntityId, (int) $trainerId, 'internal trainer');
        }
        if ($draft->targetDepartmentEntityId !== null) {
            WorkforceOrganizationUnitProjection::query()->forCompany($tenantId, $companyEntityId)
                ->where('active', true)->where('workforce_entity_id', $draft->targetDepartmentEntityId)->first()
                ?? throw new InvalidTrainingEventException('Choose an active target department from this company.');
        }
        $externalName = $this->trimNullable($draft->externalTrainerName);
        $externalReference = $this->trimNullable($draft->externalTrainerReference);
        if ($trainerId === null && $externalName === null) {
            throw new InvalidTrainingEventException('Choose an internal trainer or name an external trainer/provider.');
        }
        if ($externalReference !== null && $externalName === null) {
            throw new InvalidTrainingEventException('An external trainer reference requires a visible provider name snapshot.');
        }

        return $course;
    }

    private function employee(int $tenantId, int $companyEntityId, int $employeeEntityId, string $field): void
    {
        WorkforceEmployeeProjection::query()->forCompany($tenantId, $companyEntityId)
            ->where('active', true)->where('workforce_entity_id', $employeeEntityId)->first()
            ?? throw new InvalidTrainingEventException("Choose an active $field from this company.");
    }

    /** @param array<string, mixed> $attributes */
    private function transition(int $companyEntityId, int $eventId, TrainingEventStatus $allowed,
        TrainingEventStatus $to, string $type, ?string $comment, ?string $evidence,
        ?int $actorUserId, ?int $actorEmployeeEntityId, array $attributes = []): TrainingEvent
    {
        return DB::transaction(function () use ($companyEntityId, $eventId, $allowed, $to, $type, $comment, $evidence, $actorUserId, $actorEmployeeEntityId, $attributes): TrainingEvent {
            $event = $this->find($companyEntityId, $eventId, true);
            if ($event->status !== $allowed) {
                throw new InvalidTrainingEventException("Cannot move {$event->status->label()} to {$to->label()}.");
            }
            $from = $event->status;
            $event->update($attributes + ['status' => $to]);
            $this->record($event, $type, $from, $to, $comment, $evidence, $actorUserId, $actorEmployeeEntityId);

            return $event->refresh();
        });
    }

    private function find(int $companyEntityId, int $eventId, bool $lock = false): TrainingEvent
    {
        $query = TrainingEvent::query()->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)->whereKey($eventId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new TrainingEventNotFoundException('Training event was not found in this company.');
    }

    private function record(TrainingEvent $event, string $type, ?TrainingEventStatus $from, ?TrainingEventStatus $to,
        ?string $comment, ?string $evidence, ?int $actorUserId, ?int $actorEmployeeEntityId): void
    {
        TrainingEventAuditEvent::query()->create([
            'tenant_id' => $event->tenant_id,
            'company_entity_id' => $event->company_entity_id,
            'training_event_id' => $event->id,
            'event_type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'comment' => $comment,
            'evidence' => $evidence,
            'actor_user_id' => $actorUserId,
            'actor_employee_entity_id' => $actorEmployeeEntityId,
            'occurred_at' => now(),
        ]);
    }

    private function requireText(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidTrainingEventException($message);
        }
    }

    private function trimNullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
