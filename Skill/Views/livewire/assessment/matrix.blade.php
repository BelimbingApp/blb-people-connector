<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Skill assessments')"
        :subtitle="__('Assessment matrix — scored cells are submitted for independent HOD verification.')"
    />

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    @error('matrix')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">
            {{ __('No company workforce data is synchronized yet.') }}
        </x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex items-center gap-2 text-sm">
                <span class="text-muted">{{ __('Company') }}</span>
                @foreach ($companies as $entityId => $name)
                    <button
                        type="button"
                        wire:click="selectCompany({{ $entityId }})"
                        class="{{ $companyEntityId === $entityId ? 'font-medium text-ink' : 'text-muted' }}"
                    >{{ $name }}</button>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap gap-3 text-sm">
            <label>{{ __('Cycle') }}
                <select wire:model="cycle" class="ms-1">
                    @foreach (\App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('Method') }}
                <select wire:model="method" class="ms-1">
                    @foreach (\App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-64 grow">{{ __('Shared evidence (used when a cell has none)') }}
                <x-ui.input wire:model="sharedEvidence" class="mt-1" />
            </label>
        </div>

        <div>
            <p class="mb-2 text-sm font-medium">{{ __('Skills (up to 12)') }}</p>
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($skills as $skill)
                    <button
                        type="button"
                        wire:click="toggleSkill({{ $skill->id }})"
                        @disabled(! $canAssess)
                        class="rounded border px-2 py-1 {{ in_array($skill->id, $selectedSkillIds, true) ? 'border-accent bg-accent/10' : 'border-edge' }}"
                    >
                        {{ $skill->code }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($selectedSkills->isEmpty())
            <x-ui.alert variant="info">{{ __('Select skills to open the matrix.') }}</x-ui.alert>
        @elseif ($employees->isEmpty())
            <x-ui.alert variant="info">{{ __('No active employees in this company projection.') }}</x-ui.alert>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-edge text-left">
                            <th class="p-2">{{ __('Employee') }}</th>
                            @foreach ($selectedSkills as $skill)
                                <th class="p-2">
                                    {{ $skill->code }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr class="border-b border-edge/60" wire:key="emp-{{ $employee->workforce_entity_id }}">
                                <td class="p-2 font-medium">{{ $employee->display_name }}</td>
                                @foreach ($selectedSkills as $skill)
                                    @php($key = $employee->workforce_entity_id.':'.$skill->id)
                                    <td class="p-2 align-top">
                                        @if (isset($requiredLevels[$key]))
                                            <div class="mb-1 text-xs text-muted">{{ __('Required') }} {{ $requiredLevels[$key] }}</div>
                                        @endif
                                        <x-ui.input
                                            type="number"
                                            min="0"
                                            max="5"
                                            wire:model="scores.{{ $key }}"
                                            :disabled="! $canAssess"
                                            class="w-16"
                                            :aria-label="__('Score for :employee on :skill', ['employee' => $employee->display_name, 'skill' => $skill->code])"
                                        />
                                        <x-ui.input
                                            wire:model="evidence.{{ $key }}"
                                            :placeholder="__('Evidence')"
                                            :disabled="! $canAssess"
                                            class="mt-1"
                                        />
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canAssess)
                <x-ui.button wire:click="saveMatrix">{{ __('Submit scored rows for HOD verification') }}</x-ui.button>
            @endif
        @endif
    @endif
</div>
