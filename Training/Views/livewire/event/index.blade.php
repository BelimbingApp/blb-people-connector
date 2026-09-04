<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training schedule')"
        :subtitle="__('Schedule connector-owned events and keep the complete event register available even when a provider is offline.')"
    />

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @error('event')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No authorized workforce company is available.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Workforce company') }}">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        @if ($canManage)
            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $editingEventId === null ? __('Schedule an event') : __('Revise scheduled event') }}</h2>
                        <p class="text-sm text-muted">{{ __('Participant attendance and results are recorded separately; this schedule never changes proficiency.') }}</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <x-ui.select id="training-event-course" :label="__('Course')" wire:model="courseId" required>
                            <option value="">{{ __('Choose a course') }}</option>
                            @foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->code }} · {{ $course->title }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-department" :label="__('Target department')" wire:model="targetDepartmentEntityId">
                            <option value="">{{ __('Company-wide (visible to every HOD)') }}</option>
                            @foreach ($departments as $department)<option value="{{ $department->workforce_entity_id }}">{{ $department->name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-organizer" :label="__('Accountable organiser')" wire:model="organizerEmployeeEntityId" required>
                            <option value="">{{ __('Choose one person') }}</option>
                            @foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-trainer" :label="__('Internal trainer')" wire:model="internalTrainerEmployeeEntityId">
                            <option value="">{{ __('Use course trainer or external provider') }}</option>
                            @foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-mode" :label="__('Delivery mode')" wire:model="deliveryMode">
                            <option value="">{{ __('Use course default') }}</option>
                            @foreach (\App\Domains\PeopleConnector\Training\Enums\DeliveryMode::cases() as $mode)<option value="{{ $mode->value }}">{{ $mode->label() }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input id="training-event-venue" :label="__('Venue / meeting link')" wire:model="venue" />
                        <x-ui.input id="training-event-external-name" :label="__('External trainer / provider')" wire:model="externalTrainerName" />
                        <x-ui.input id="training-event-external-reference" :label="__('Provider-neutral reference')" wire:model="externalTrainerReference" />
                        <x-ui.input id="training-event-capacity" type="number" min="1" :label="__('Capacity')" wire:model="capacity" required />
                        <x-ui.input id="training-event-start" type="datetime-local" :label="__('Starts')" wire:model="startsAt" required />
                        <x-ui.input id="training-event-end" type="datetime-local" :label="__('Ends')" wire:model="endsAt" required />
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button wire:click="save">{{ $editingEventId === null ? __('Schedule event') : __('Save revision') }}</x-ui.button>
                        @if ($editingEventId !== null)<x-ui.button variant="secondary" wire:click="cancelEdit">{{ __('Cancel editing') }}</x-ui.button>@endif
                    </div>
                </div>
            </x-ui.card>
        @endif

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Event register') }}</h2>
                <p class="text-sm text-muted">{{ __('Completed and cancelled events remain visible with their audit trail. Participation metrics appear only when the participant record supplies them.') }}</p>
            </div>

            @if ($events->isEmpty())
                <x-ui.alert variant="info">{{ __('No training events are visible for this company and department scope.') }}</x-ui.alert>
            @else
                @foreach ($events as $event)
                    @php($summary = $summaries[$event->id] ?? \App\Domains\PeopleConnector\Training\Data\TrainingParticipationSummary::unavailable())
                    <x-ui.card wire:key="training-event-{{ $event->id }}">
                        <article class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-medium tracking-tight">{{ $event->course_code_snapshot }} · {{ $event->course_title_snapshot }}</h3>
                                    <p class="text-sm text-muted">{{ $event->delivery_mode_snapshot->label() }} · {{ $event->status->label() }}</p>
                                </div>
                                <div class="text-right text-sm tabular-nums">
                                    <x-ui.datetime :value="$event->starts_at" />
                                    <p>{{ __('Capacity :capacity', ['capacity' => $event->capacity]) }}</p>
                                </div>
                            </div>

                            <dl class="grid gap-2 text-sm md:grid-cols-3">
                                <div><dt class="text-muted">{{ __('Department') }}</dt><dd>{{ $event->target_department_entity_id === null ? __('Company-wide') : ($departments->firstWhere('workforce_entity_id', $event->target_department_entity_id)?->name ?? __('Unavailable')) }}</dd></div>
                                <div><dt class="text-muted">{{ __('Organiser') }}</dt><dd>{{ $employees->firstWhere('workforce_entity_id', $event->organizer_employee_entity_id)?->display_name ?? __('Unavailable') }}</dd></div>
                                <div><dt class="text-muted">{{ __('Trainer / provider') }}</dt><dd>{{ $event->external_trainer_name_snapshot ?: ($employees->firstWhere('workforce_entity_id', $event->internal_trainer_employee_entity_id)?->display_name ?? __('Unavailable')) }}</dd></div>
                                <div><dt class="text-muted">{{ __('Venue') }}</dt><dd>{{ $event->venue ?: __('Not specified') }}</dd></div>
                                <div><dt class="text-muted">{{ __('Ends') }}</dt><dd><x-ui.datetime :value="$event->ends_at" /></dd></div>
                                <div><dt class="text-muted">{{ __('Participation') }}</dt><dd>@if ($summary->isAvailable()){{ __(':attended attended · :completed completed · :rate% pass', ['attended' => $summary->attended, 'completed' => $summary->completed, 'rate' => $summary->passRate() ?? '—']) }}@else{{ __('Not recorded by the participant register yet') }}@endif</dd></div>
                            </dl>

                            @if ($event->completion_evidence)<p class="text-sm"><span class="font-medium">{{ __('Completion evidence:') }}</span> {{ $event->completion_evidence }}</p>@endif
                            @if ($event->cancellation_reason)<p class="text-sm"><span class="font-medium">{{ __('Cancellation reason:') }}</span> {{ $event->cancellation_reason }}</p>@endif

                            <x-ui.disclosure :title="__('History (:count)', ['count' => ($history[$event->id] ?? collect())->count()])" panel-id="training-event-{{ $event->id }}-history">
                                <ol class="space-y-2 text-sm">
                                    @foreach ($history[$event->id] ?? [] as $record)
                                        <li><span class="font-medium">{{ str($record->event_type)->replace('_', ' ')->title() }}</span> · <x-ui.datetime :value="$record->occurred_at" />@if ($record->comment)<p>{{ $record->comment }}</p>@endif @if ($record->evidence)<p class="text-muted">{{ $record->evidence }}</p>@endif</li>
                                    @endforeach
                                </ol>
                            </x-ui.disclosure>

                            @if ($canManage && ! $event->status->isTerminal())
                                <div class="flex flex-wrap gap-2">
                                    @if ($event->status === \App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus::Scheduled)
                                        <x-ui.button wire:click="editEvent({{ $event->id }})">{{ __('Revise') }}</x-ui.button>
                                        <x-ui.button wire:click="start({{ $event->id }})">{{ __('Start') }}</x-ui.button>
                                    @endif
                                </div>
                                @if ($event->status === \App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus::InProgress)
                                    <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-evidence" :label="__('Completion evidence')" wire:model="evidence.{{ $event->id }}" /><x-ui.button wire:click="complete({{ $event->id }})">{{ __('Complete') }}</x-ui.button></div>
                                @endif
                                <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-reason" :label="__('Cancellation reason')" wire:model="reason.{{ $event->id }}" /><x-ui.button wire:click="cancel({{ $event->id }})">{{ __('Cancel event') }}</x-ui.button></div>
                            @endif
                            @if ($canManage)
                                <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-comment" :label="__('Audit note')" wire:model="comment.{{ $event->id }}" /><x-ui.button wire:click="addComment({{ $event->id }})">{{ __('Add note') }}</x-ui.button></div>
                            @endif
                        </article>
                    </x-ui.card>
                @endforeach
            @endif
        </section>
    @endif
</div>
