<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Skills')"
        :subtitle="__('Company skill catalog and proficiency scale.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">
            {{ __('No company workforce data is synchronized yet. Connect a People provider to start the skill catalog.') }}
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

        <nav class="flex gap-4 border-b border-edge text-sm" role="tablist">
            @foreach (['skills' => __('Skills'), 'categories' => __('Categories'), 'scale' => __('Proficiency Scale')] as $key => $label)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="$set('tab', '{{ $key }}')"
                    class="pb-2 {{ $tab === $key ? 'border-b-2 border-accent font-medium text-ink' : 'text-muted' }}"
                >{{ $label }}</button>
            @endforeach
        </nav>

        @if ($canManage && $categories->isEmpty() && $scales->isEmpty())
            <x-ui.alert variant="info">
                {{ __('This catalog is empty.') }}
                <button type="button" wire:click="installStarterPack" class="font-medium underline">
                    {{ __('Install the standard categories and 0–5 scale') }}
                </button>
            </x-ui.alert>
        @endif

        @if ($tab === 'skills')
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <x-ui.input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search code or name')" />
                <select wire:model.live="filterCategoryId" class="text-sm">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1">
                    <input type="checkbox" wire:model.live="criticalOnly" /> {{ __('Critical only') }}
                </label>
                <label class="flex items-center gap-1">
                    <input type="checkbox" wire:model.live="includeInactive" /> {{ __('Include inactive') }}
                </label>
                @if ($canManage)
                    <x-ui.button size="sm" wire:click="startSkill">{{ __('New skill') }}</x-ui.button>
                @endif
            </div>

            @error('skills')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            @if ($editingSkillId !== null || $skillForm !== [])
                <form wire:submit="saveSkill" class="space-y-3 rounded border border-edge p-4">
                    @error('skillForm')
                        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                    @enderror
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">{{ __('Skill ID (stable code)') }}
                            <x-ui.input wire:model="skillForm.code" :disabled="$editingSkillId !== null" required />
                        </label>
                        <label class="text-sm">{{ __('Name') }}
                            <x-ui.input wire:model="skillForm.name" required />
                        </label>
                        <label class="text-sm">{{ __('Category') }}
                            <select wire:model="skillForm.category_id" class="w-full text-sm">
                                @foreach ($categories->where('active', true) as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">{{ __('Scope') }}
                            <select wire:model="skillForm.scope" class="w-full text-sm">
                                @foreach ($scopeOptions as $scope)
                                    <option value="{{ $scope->value }}">{{ ucfirst($scope->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">{{ __('Critical classification') }}
                            <select wire:model="skillForm.critical_classification" class="w-full text-sm">
                                <option value="">{{ __('Not critical') }}</option>
                                @foreach ($classificationOptions as $classification)
                                    <option value="{{ $classification->value }}">{{ ucfirst($classification->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">{{ __('Default assessment method') }}
                            <select wire:model="skillForm.default_assessment_method" class="w-full text-sm">
                                @foreach ($methodOptions as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">{{ __('Reassessment (months)') }}
                            <x-ui.input type="number" min="1" wire:model="skillForm.default_reassessment_months" />
                        </label>
                    </div>
                    <label class="block text-sm">{{ __('Definition / standard') }}
                        <textarea wire:model="skillForm.definition" rows="2" class="w-full text-sm" required></textarea>
                    </label>
                    <label class="block text-sm">{{ __('Minimum evidence guide') }}
                        <textarea wire:model="skillForm.evidence_guide" rows="2" class="w-full text-sm"></textarea>
                    </label>
                    <div class="flex gap-2">
                        <x-ui.button type="submit" size="sm">{{ __('Save skill') }}</x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelSkill">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif

            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Skill ID') }}</x-ui.th>
                        <x-ui.th>{{ __('Skill') }}</x-ui.th>
                        <x-ui.th>{{ __('Category') }}</x-ui.th>
                        <x-ui.th>{{ __('Scope') }}</x-ui.th>
                        <x-ui.th>{{ __('Critical') }}</x-ui.th>
                        <x-ui.th>{{ __('Method') }}</x-ui.th>
                        <x-ui.th>{{ __('Cadence') }}</x-ui.th>
                        @if ($canManage)
                            <x-ui.th>{{ __('Actions') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @forelse ($skills as $skill)
                        <tr wire:key="skill-{{ $skill->id }}" @class(['opacity-60' => ! $skill->active])>
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $skill->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                <span class="font-medium">{{ $skill->name }}</span>
                                <span class="block text-muted">{{ $skill->definition }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $skill->category?->name }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ ucfirst($skill->scope->value) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                @if ($skill->isCritical())
                                    <x-ui.badge variant="warning">{{ ucfirst($skill->critical_classification->value) }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $skill->default_assessment_method->label() }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">
                                {{ $skill->default_reassessment_months !== null ? __(':months mo', ['months' => $skill->default_reassessment_months]) : '—' }}
                            </td>
                            @if ($canManage)
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <button type="button" class="text-accent" wire:click="startSkill({{ $skill->id }})">{{ __('Edit') }}</button>
                                    <button type="button" class="ml-2 text-muted" wire:click="toggleSkillActive({{ $skill->id }})">
                                        {{ $skill->active ? __('Deactivate') : __('Reactivate') }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                {{ __('No skills match the current filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </x-slot:body>
            </x-ui.table>
        @elseif ($tab === 'categories')
            @error('categoryForm')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            @if ($canManage)
                <form wire:submit="saveCategory" class="flex flex-wrap items-end gap-2 text-sm">
                    <label>{{ __('Code') }} <x-ui.input wire:model="newCategoryCode" required /></label>
                    <label>{{ __('Name') }} <x-ui.input wire:model="newCategoryName" required /></label>
                    <x-ui.button type="submit" size="sm">{{ __('Add category') }}</x-ui.button>
                </form>
            @endif

            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Code') }}</x-ui.th>
                        <x-ui.th>{{ __('Category') }}</x-ui.th>
                        <x-ui.th>{{ __('Skills') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                        @if ($canManage)
                            <x-ui.th>{{ __('Actions') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @foreach ($categories as $category)
                        <tr wire:key="category-{{ $category->id }}">
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $category->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">
                                @if ($canManage)
                                    <input
                                        type="text"
                                        value="{{ $category->name }}"
                                        wire:change="renameCategory({{ $category->id }}, $event.target.value)"
                                        class="w-full bg-transparent text-sm font-medium text-ink"
                                        aria-label="{{ __('Rename category :name', ['name' => $category->name]) }}"
                                    />
                                @else
                                    {{ $category->name }}
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $category->skills()->count() }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                <x-ui.badge :variant="$category->active ? 'success' : 'neutral'">
                                    {{ $category->active ? __('Active') : __('Inactive') }}
                                </x-ui.badge>
                            </td>
                            @if ($canManage)
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <button type="button" class="text-muted" wire:click="toggleCategoryActive({{ $category->id }})">
                                        {{ $category->active ? __('Deactivate') : __('Reactivate') }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
        @else
            @foreach ($scales as $scale)
                <section wire:key="scale-{{ $scale->id }}" class="space-y-2 rounded border border-edge p-4">
                    <header class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-medium text-ink">{{ $scale->name }}</h2>
                        <span class="font-mono text-sm text-muted">{{ $scale->code }} · v{{ $scale->version }}</span>
                        <x-ui.badge :variant="match ($scale->status->value) { 'published' => 'success', 'draft' => 'info', default => 'neutral' }">
                            {{ ucfirst($scale->status->value) }}
                        </x-ui.badge>
                        @if ($canManage)
                            @if ($scale->status->value === 'draft')
                                <x-ui.button size="sm" wire:click="publishScale({{ $scale->id }})">{{ __('Publish') }}</x-ui.button>
                            @elseif ($scale->status->value === 'published')
                                <x-ui.button size="sm" variant="ghost" wire:click="draftNewScaleVersion({{ $scale->id }})">
                                    {{ __('Draft new version') }}
                                </x-ui.button>
                            @endif
                        @endif
                    </header>
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('Level') }}</x-ui.th>
                                <x-ui.th>{{ __('Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Behavioural anchor') }}</x-ui.th>
                                <x-ui.th>{{ __('Authority') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach ($scale->levels as $level)
                                <tr wire:key="level-{{ $level->id }}">
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $level->level }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $level->name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm">{{ $level->anchor }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm">{{ $level->authority }}</td>
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>
                </section>
            @endforeach

            @if ($scales->isEmpty())
                <p class="text-sm text-muted">{{ __('No proficiency scale yet. Install the starter pack to ship the standard 0–5 scale.') }}</p>
            @endif
        @endif
    @endif
</div>
