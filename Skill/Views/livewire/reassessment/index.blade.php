<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Reassessment requests')"
        :subtitle="__('Assigned reevaluation work after training, development actions, or expired certification. Opening a request never changes proficiency by itself.')"
    />

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @error('request')
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
                <h2 class="text-lg font-semibold">{{ __('Open requests') }}</h2>
                <p class="text-sm text-muted">{{ __('Due soonest first. Fulfillment is a finalized Assessment Log row for the same employee, skill, and cycle.') }}</p>
            </div>

            @if ($requests->isEmpty())
                <x-ui.alert variant="info">{{ __('No open reassessment requests in your audience.') }}</x-ui.alert>
            @else
                <x-ui.table :caption="__('Open reassessment requests')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Employee / skill') }}</x-ui.th>
                            <x-ui.th>{{ __('Source') }}</x-ui.th>
                            <x-ui.th>{{ __('Target') }}</x-ui.th>
                            <x-ui.th>{{ __('Due') }}</x-ui.th>
                            @if ($canManage)
                                <x-ui.th>{{ __('Cancel') }}</x-ui.th>
                            @endif
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($requests as $request)
                            <tr wire:key="reassessment-{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y">
                                    {{ $employeeNames[$request->employee_entity_id] ?? __('Unknown employee') }}
                                    /
                                    {{ $skillNames[$request->skill_id] ?? __('Unknown skill') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">{{ str_replace('_', ' ', $request->source->value) }}</td>
                                <td class="px-table-cell-x py-table-cell-y tabular-nums">
                                    {{ $request->before_level === null ? '—' : $request->before_level }}
                                    → {{ $request->target_level }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y tabular-nums">
                                    {{ $request->due_date->toDateString() }}
                                    @if ($request->daysUntilDue() < 0)
                                        <br><span class="font-semibold text-status-danger">{{ __('Overdue') }}</span>
                                    @elseif ($request->daysUntilDue() <= 7)
                                        <br><span class="font-semibold text-status-warning">{{ __('Due soon') }}</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                            <x-ui.input
                                                :id="'reassessment-cancel-'.$request->id"
                                                :label="__('Reason')"
                                                wire:model="cancelReason.{{ $request->id }}"
                                                required
                                            />
                                            <x-ui.button type="button" variant="secondary" wire:click="cancel({{ $request->id }})">
                                                {{ __('Cancel') }}
                                            </x-ui.button>
                                        </div>
                                        @error('cancelReason.'.$request->id)
                                            <p class="mt-1 text-sm text-status-danger">{{ $message }}</p>
                                        @enderror
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        @if ($canManage)
            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ __('Open a manual request') }}</h2>
                    <p class="text-sm text-muted">{{ __('Authorized evaluators can assign reassessment work. This does not change the employee’s current score.') }}</p>
                </div>
                <x-ui.card>
                    <div class="grid gap-3 md:grid-cols-2">
                        <x-ui.select id="reassessment-employee" :label="__('Employee')" wire:model="employeeEntityId" required>
                            <option value="">{{ __('Choose one person') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select id="reassessment-skill" :label="__('Skill')" wire:model="skillId" required>
                            <option value="">{{ __('Choose one skill') }}</option>
                            @foreach ($skills as $skill)
                                <option value="{{ $skill->id }}">{{ $skill->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input id="reassessment-target" type="number" min="0" max="5" :label="__('Target level')" wire:model="targetLevel" required />
                        <x-ui.input id="reassessment-due" type="date" :label="__('Due date')" wire:model="dueDate" required />
                        <x-ui.input id="reassessment-evidence" :label="__('Required evidence')" wire:model="requiredEvidence" class="md:col-span-2" />
                        <x-ui.input id="reassessment-notes" :label="__('Notes')" wire:model="notes" class="md:col-span-2" />
                        <div class="md:col-span-2">
                            <x-ui.button wire:click="openManual">{{ __('Open reassessment request') }}</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            </section>
        @endif
    @endif
</div>
