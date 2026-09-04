<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Development actions')"
        :subtitle="__('Turn verified skill gaps into named commitments. Finishing an activity still requires an independent reassessment.')"
    />

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @error('actions')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company workforce data is synchronized yet.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Current gaps') }}</h2>
                <p class="text-sm text-muted">{{ __('Mandatory gates are listed first. The visible score is gap × criticality multiplier; mandatory gates escalate independently.') }}</p>
            </div>

            @if ($gaps->isEmpty())
                <x-ui.alert variant="info">{{ __('No current assessed gaps need an action.') }}</x-ui.alert>
            @else
                <x-ui.table :caption="__('Current assessed skill gaps')">
                    <x-slot:head><tr>
                        @if ($canManage)<x-ui.th><span class="sr-only">{{ __('Select') }}</span></x-ui.th>@endif
                        <x-ui.th>{{ __('Employee / skill') }}</x-ui.th>
                        <x-ui.th>{{ __('Levels') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('Priority') }}</x-ui.th>
                    </tr></x-slot:head>
                    <x-slot:body>
                            @foreach ($gaps as $gap)
                                <tr wire:key="gap-{{ $gap->id }}">
                                    @if ($canManage)
                                        <td class="px-table-cell-x py-table-cell-y"><x-ui.checkbox id="gap-{{ $gap->id }}-selected" wire:click="toggleAssessment({{ $gap->id }})" :checked="in_array($gap->id, $selectedAssessmentIds, true)" :aria-label="__('Select gap for employee :employee and skill :skill', ['employee' => $gap->employee_entity_id, 'skill' => $gap->skill_id])" /></td>
                                    @endif
                                    <td class="px-table-cell-x py-table-cell-y">{{ $employeeNames[$gap->employee_entity_id] ?? __('Unknown employee') }} / {{ $skillNames[$gap->skill_id] ?? __('Unknown skill') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y tabular-nums">{{ $gap->assessed_level }} → {{ $gap->required_level }} ({{ __('gap') }} {{ $gap->gap }})</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">
                                        @if ($gap->mandatory_gate)<span class="font-semibold text-status-danger">{{ __('Mandatory gate') }}</span><br>@endif
                                        {{ $gap->gap }} × {{ $gap->criticality->multiplier() }} = {{ $gap->gap * $gap->criticality->multiplier() }}
                                        @if ($gap->finalized_at?->copy()->addWeekdays(10)->isPast())<br><span class="font-semibold text-status-danger">{{ __('Action definition overdue') }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif

            @if ($canManage && $gaps->isNotEmpty())
                <x-ui.card><div class="grid gap-3 md:grid-cols-2">
                    <x-ui.select id="development-action-type" :label="__('Action type')" wire:model="actionType" required>
                            @foreach (\App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                    </x-ui.select>
                    <x-ui.input id="development-action-objective" :label="__('Objective')" wire:model="objective" required />
                    <x-ui.input id="development-action-intervention" :label="__('Specific intervention')" wire:model="intervention" required />
                    <x-ui.input id="development-action-evidence" :label="__('Expected evidence / outcome')" wire:model="expectedEvidence" required />
                    <x-ui.select id="development-action-owner" :label="__('Accountable HOD / owner')" wire:model="ownerEmployeeEntityId" required><option value="">{{ __('Choose one person') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach</x-ui.select>
                    <x-ui.select id="development-action-hr" :label="__('HR coordinator')" wire:model="hrCoordinatorEmployeeEntityId" required><option value="">{{ __('Choose one person') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach</x-ui.select>
                    <x-ui.select id="development-action-trainer" :label="__('Trainer / coach')" wire:model="trainerEmployeeEntityId"><option value="">{{ __('External or not applicable') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach</x-ui.select>
                    <x-ui.input id="development-action-provider" :label="__('External trainer / provider')" wire:model="trainerProviderName" />
                    <x-ui.input id="development-action-start" type="date" :label="__('Start date')" wire:model="startDate" required />
                    <x-ui.input id="development-action-due" type="date" :label="__('Due date')" wire:model="dueDate" required />
                    <div class="md:col-span-2"><x-ui.button wire:click="propose">{{ __('Create selected proposals') }}</x-ui.button></div>
                </div></x-ui.card>
            @endif
        </section>

        <section class="space-y-4">
            <div><h2 class="text-lg font-semibold">{{ __('Open commitments') }}</h2><p class="text-sm text-muted">{{ __('Gaps without an approved owner remain above; overdue commitments remain visible here until resolved.') }}</p></div>
            @if ($actions->isEmpty())
                <x-ui.alert variant="info">{{ __('No open development actions.') }}</x-ui.alert>
            @else
                @foreach ($actions as $action)
                    <x-ui.card wire:key="action-{{ $action->id }}"><article class="space-y-2">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div><h3 class="font-medium tracking-tight">{{ $action->employee_name_snapshot }} · {{ $skillNames[$action->skill_id] ?? __('Unknown skill') }}</h3><p class="text-sm text-muted">{{ $action->action_type->label() }} · {{ $action->status->label() }} · {{ $action->closure_status->label() }}</p></div>
                            <div class="text-right text-sm tabular-nums"><p>{{ $action->mandatory_gate ? __('Mandatory gate') : __('Priority :score', ['score' => $action->priority_score]) }}</p>@if ($action->daysOverdue() > 0)<p class="font-semibold text-status-danger">{{ trans_choice(':count day overdue|:count days overdue', $action->daysOverdue(), ['count' => $action->daysOverdue()]) }}</p>@endif</div>
                        </div>
                        <p>{{ $action->objective }}</p><p class="text-sm text-muted">{{ $action->intervention }} · {{ __('Expected evidence: :evidence', ['evidence' => $action->expected_evidence]) }}</p><p class="text-sm text-muted">{{ $action->priority_explanation }}</p>
                        <dl class="grid gap-2 text-sm md:grid-cols-3"><div><dt class="text-muted">{{ __('Owner') }}</dt><dd>{{ $employeeNames[$action->owner_employee_entity_id] ?? __('Unavailable') }}</dd></div><div><dt class="text-muted">{{ __('HR coordinator') }}</dt><dd>{{ $employeeNames[$action->hr_coordinator_employee_entity_id] ?? __('Unavailable') }}</dd></div><div><dt class="text-muted">{{ __('Due') }}</dt><dd><x-ui.datetime :value="$action->due_date" format="date" /></dd></div></dl>
                        <x-ui.disclosure :title="__('History (:count)', ['count' => ($history[$action->id] ?? collect())->count()])" panel-id="action-{{ $action->id }}-history">
                            <ol class="space-y-2 text-sm">
                                @foreach ($history[$action->id] ?? [] as $event)
                                    @php($eventLabel = match ($event->event_type) {
                                        'gap_proposed' => __('Proposed from assessed gap'),
                                        'manually_proposed' => __('Manually proposed'),
                                        'proposal_tailored' => __('Proposal tailored'),
                                        'approved' => __('Approved'),
                                        'started' => __('Started'),
                                        'put_on_hold' => __('Put on hold'),
                                        'intervention_completed' => __('Intervention completed'),
                                        'reassessment_linked' => __('Reassessment linked'),
                                        'cancelled' => __('Cancelled'),
                                        'commented' => __('Comment added'),
                                        default => __('Updated'),
                                    })
                                    <li><span class="font-medium">{{ $eventLabel }}</span> · <x-ui.datetime :value="$event->occurred_at" />@if ($event->comment)<p>{{ $event->comment }}</p>@endif @if ($event->evidence)<p class="text-muted">{{ $event->evidence }}</p>@endif</li>
                                @endforeach
                            </ol>
                        </x-ui.disclosure>
                        @if ($canManage)
                            <div class="flex flex-wrap gap-2">
                                @if ($action->status === \App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::Proposed)<x-ui.button wire:click="tailor({{ $action->id }})">{{ __('Apply form to proposal') }}</x-ui.button><x-ui.button wire:click="approve({{ $action->id }})">{{ __('Approve') }}</x-ui.button>@endif
                                @if (in_array($action->status, [\App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::NotStarted, \App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::Scheduled, \App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::OnHold], true))<x-ui.button wire:click="start({{ $action->id }})">{{ __('Start') }}</x-ui.button>@endif
                            </div>
                            @if ($action->status->isOpen() && $action->status !== \App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::PendingReassessment)
                                <div class="grid gap-2 md:grid-cols-2"><x-ui.input id="action-{{ $action->id }}-completion-evidence" :label="__('Completion evidence')" wire:model="completionEvidence.{{ $action->id }}" /><x-ui.input id="action-{{ $action->id }}-reassessment-due" type="date" :label="__('Reassessment due')" wire:model="reassessmentDue.{{ $action->id }}" /><div><x-ui.button wire:click="complete({{ $action->id }})">{{ __('Complete intervention') }}</x-ui.button></div></div>
                            @endif
                            @if ($action->status === \App\Domains\PeopleConnector\Skill\Enums\DevelopmentActionStatus::PendingReassessment)
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-ui.select id="action-{{ $action->id }}-post-assessment" wire:model="postAssessmentId.{{ $action->id }}" :label="__('Independent reassessment')">
                                        <option value="">{{ __('Choose finalized reassessment') }}</option>
                                        @foreach ($eligibleReassessments[$action->id] ?? [] as $assessment)
                                            <option value="{{ $assessment->id }}">{{ __('Level :level on :date', ['level' => $assessment->assessed_level, 'date' => $assessment->assessed_at->toDateString()]) }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.button wire:click="verifyReassessment({{ $action->id }})">{{ __('Verify outcome') }}</x-ui.button>
                                </div>
                            @endif
                            @if ($action->status->isOpen())<div class="flex gap-2"><x-ui.input id="action-{{ $action->id }}-cancellation-reason" wire:model="reason.{{ $action->id }}" :label="__('Cancellation reason')" /><x-ui.button wire:click="cancel({{ $action->id }})">{{ __('Cancel') }}</x-ui.button></div>@endif
                            <div class="grid gap-2 md:grid-cols-2"><x-ui.input id="action-{{ $action->id }}-comment" wire:model="actionComment.{{ $action->id }}" :label="__('Comment')" /><x-ui.input id="action-{{ $action->id }}-comment-evidence" wire:model="actionEvidence.{{ $action->id }}" :label="__('Evidence reference')" /><div><x-ui.button wire:click="addComment({{ $action->id }})">{{ __('Add update') }}</x-ui.button></div></div>
                        @endif
                    </article></x-ui.card>
                @endforeach
            @endif
        </section>

        <section class="space-y-4">
            <div><h2 class="text-lg font-semibold">{{ __('Completed and cancelled') }}</h2><p class="text-sm text-muted">{{ __('Terminal commitments and their evidence remain available as the action register.') }}</p></div>
            @if ($terminalActions->isEmpty())
                <x-ui.alert variant="info">{{ __('No completed or cancelled development actions.') }}</x-ui.alert>
            @else
                @foreach ($terminalActions as $action)
                    <x-ui.card wire:key="terminal-action-{{ $action->id }}"><article class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div><h3 class="font-medium tracking-tight">{{ $action->employee_name_snapshot }} · {{ $skillNames[$action->skill_id] ?? __('Unknown skill') }}</h3><p class="text-sm text-muted">{{ $action->action_type->label() }} · {{ $action->status->label() }} · {{ $action->closure_status->label() }}</p></div>
                            <div class="text-right text-sm"><p>{{ __('Owner: :owner', ['owner' => $employeeNames[$action->owner_employee_entity_id] ?? __('Unavailable')]) }}</p><p><x-ui.datetime :value="$action->updated_at" /></p></div>
                        </div>
                        <p>{{ $action->objective }}</p>
                        <dl class="grid gap-2 text-sm md:grid-cols-3">
                            <div><dt class="text-muted">{{ __('Completion evidence') }}</dt><dd>{{ $action->completion_evidence ?? __('Not applicable') }}</dd></div>
                            <div><dt class="text-muted">{{ __('Reassessment result') }}</dt><dd>@if ($action->post_level !== null){{ __('Level :level · improvement :change', ['level' => $action->post_level, 'change' => $action->improvement]) }}@else{{ __('Not applicable') }}@endif</dd></div>
                            <div><dt class="text-muted">{{ __('Due') }}</dt><dd><x-ui.datetime :value="$action->due_date" format="date" /></dd></div>
                        </dl>
                        <x-ui.disclosure :title="__('Full history (:count)', ['count' => ($history[$action->id] ?? collect())->count()])" panel-id="terminal-action-{{ $action->id }}-history">
                            <ol class="space-y-2 text-sm">
                                @foreach ($history[$action->id] ?? [] as $event)
                                    @php($eventLabel = match ($event->event_type) {
                                        'gap_proposed' => __('Proposed from assessed gap'),
                                        'manually_proposed' => __('Manually proposed'),
                                        'proposal_tailored' => __('Proposal tailored'),
                                        'approved' => __('Approved'),
                                        'started' => __('Started'),
                                        'put_on_hold' => __('Put on hold'),
                                        'intervention_completed' => __('Intervention completed'),
                                        'reassessment_linked' => __('Reassessment linked'),
                                        'cancelled' => __('Cancelled'),
                                        'commented' => __('Comment added'),
                                        default => __('Updated'),
                                    })
                                    <li><span class="font-medium">{{ $eventLabel }}</span> · <x-ui.datetime :value="$event->occurred_at" />@if ($event->comment)<p>{{ $event->comment }}</p>@endif @if ($event->evidence)<p class="text-muted">{{ $event->evidence }}</p>@endif</li>
                                @endforeach
                            </ol>
                        </x-ui.disclosure>
                    </article></x-ui.card>
                @endforeach
            @endif
        </section>
    @endif
</div>
