<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Training catalog')" :subtitle="__('Maintain the company course catalog before scheduling delivery.')" />

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @error('courseForm')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No authorized workforce company is available.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Workforce company') }}">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">{{ $name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        @if ($canManage)
            <div class="flex justify-end"><x-ui.button wire:click="startCourse">{{ __('New course') }}</x-ui.button></div>
        @endif

        @if ($canManage && ($editingCourseId !== null || $courseForm !== []))
            <form wire:submit="saveCourse" class="space-y-3 rounded border border-edge p-4">
                <h2 class="text-lg font-semibold">{{ $editingCourseId === null ? __('Define course') : __('Revise course') }}</h2>
                <div class="grid gap-3 md:grid-cols-2">
                    <x-ui.input id="training-course-code" :label="__('Training ID (stable code)')" :error="$errors->first('courseForm.code')" wire:model="courseForm.code" :disabled="$editingCourseId !== null" required />
                    <x-ui.input id="training-course-title" :label="__('Training title')" :error="$errors->first('courseForm.title')" wire:model="courseForm.title" required />
                    <x-ui.select id="training-course-delivery-mode" :label="__('Delivery mode')" :error="$errors->first('courseForm.delivery_mode')" wire:model="courseForm.delivery_mode" required>
                        @foreach ($deliveryModes as $mode)<option value="{{ $mode->value }}">{{ $mode->label() }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.select id="training-course-trainer" :label="__('Internal trainer / mentor')" :error="$errors->first('courseForm.internal_trainer_employee_entity_id')" wire:model="courseForm.internal_trainer_employee_entity_id">
                        <option value="">{{ __('Not assigned') }}</option>
                        @foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach
                    </x-ui.select>
                </div>
                <x-ui.multi-select id="training-course-skills" :label="__('Skills covered')" :options="$skills->map(fn ($skill) => ['value' => $skill->id, 'label' => $skill->code.' · '.$skill->name])->all()" :selected="$courseForm['skill_ids'] ?? []" wire:model="courseForm.skill_ids" />
                @error('courseForm.skill_ids')<p class="text-sm text-status-danger">{{ $message }}</p>@enderror
                <x-ui.textarea id="training-course-description" :label="__('Description')" :error="$errors->first('courseForm.description')" wire:model="courseForm.description" rows="3" />
                <div class="flex gap-2"><x-ui.button type="submit">{{ __('Save course') }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="cancelCourse">{{ __('Cancel') }}</x-ui.button></div>
            </form>
        @endif

        <x-ui.table>
            <x-slot:head><tr><x-ui.th>{{ __('Training ID') }}</x-ui.th><x-ui.th>{{ __('Course') }}</x-ui.th><x-ui.th>{{ __('Delivery') }}</x-ui.th><x-ui.th>{{ __('Skills') }}</x-ui.th><x-ui.th>{{ __('Status') }}</x-ui.th>@if ($canManage)<x-ui.th>{{ __('Actions') }}</x-ui.th>@endif</tr></x-slot:head>
            <x-slot:body>
                @forelse ($courses as $course)
                    <tr wire:key="training-course-{{ $course->id }}" @class(['opacity-60' => ! $course->active])>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $course->code }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm"><span class="font-medium">{{ $course->title }}</span>@if ($course->description)<span class="block text-muted">{{ $course->description }}</span>@endif</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm">{{ $course->delivery_mode->label() }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm">{{ $course->mappedSkills()->pluck('code')->join(', ') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm"><x-ui.badge :variant="$course->active ? 'success' : 'neutral'">{{ $course->active ? __('Active') : __('Inactive') }}</x-ui.badge></td>
                        @if ($canManage)<td class="px-table-cell-x py-table-cell-y text-sm"><button type="button" class="text-accent" wire:click="editCourse({{ $course->id }})">{{ __('Edit') }}</button><button type="button" class="ml-2 text-muted" wire:click="toggleCourseActive({{ $course->id }})">{{ $course->active ? __('Deactivate') : __('Reactivate') }}</button></td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No courses are defined for this company.') }}</td></tr>
                @endforelse
            </x-slot:body>
        </x-ui.table>
    @endif
</div>
