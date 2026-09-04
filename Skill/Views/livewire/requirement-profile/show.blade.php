<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__(':name v:version', ['name' => $profile->name, 'version' => $profile->version])"
        :subtitle="__('Requirement profile :code', ['code' => $profile->code])"
    />

    <div class="flex flex-wrap items-center gap-3 text-sm">
        <x-ui.badge>{{ str($profile->status->value)->replace('_', ' ')->title() }}</x-ui.badge>
        <span class="text-muted">
            {{ __('Effective :date', ['date' => $profile->effective_date?->format('Y-m-d') ?? '—']) }}
        </span>
        @if ($profile->published_at !== null)
            <span class="text-muted">{{ __('Published :date', ['date' => $profile->published_at->format('Y-m-d H:i')]) }}</span>
        @endif
        @if ($profile->retired_at !== null)
            <span class="text-muted">{{ __('Retired :date', ['date' => $profile->retired_at->format('Y-m-d H:i')]) }}</span>
        @endif
    </div>

    <section class="space-y-3" aria-labelledby="requirement-audience-heading">
        <h2 id="requirement-audience-heading" class="text-lg font-semibold text-ink">{{ __('Applies to') }}</h2>
        <div class="flex flex-wrap gap-2">
            @foreach ($selectors as $selector)
                <x-ui.badge variant="neutral">
                    {{ str($selector->selector_type->value)->replace('_', ' ')->title() }}
                    @if ($selector->selector_value)
                        · {{ $selector->selector_value }}
                    @elseif ($selector->selector_entity_id)
                        · #{{ $selector->selector_entity_id }}
                    @endif
                </x-ui.badge>
            @endforeach
        </div>
    </section>

    <section class="space-y-3" aria-labelledby="requirements-heading">
        <h2 id="requirements-heading" class="text-lg font-semibold text-ink">{{ __('Skill requirements') }}</h2>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th>{{ __('Level') }}</x-ui.th>
                    <x-ui.th>{{ __('Criticality') }}</x-ui.th>
                    <x-ui.th>{{ __('Weight') }}</x-ui.th>
                    <x-ui.th>{{ __('Evidence standard') }}</x-ui.th>
                </tr>
            </x-slot:head>
            <x-slot:body>
                @foreach ($items as $item)
                    <tr>
                        <td class="px-table-cell-x py-table-cell-y text-sm">
                            <span class="font-medium text-ink">{{ $item->skill?->name ?? __('Unknown skill') }}</span>
                            <span class="block font-mono text-muted">{{ $item->skill?->code }}</span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $item->required_level }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm">{{ str($item->criticality->value)->title() }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $item->weight_percent }}%</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $item->evidence_standard ?: '—' }}</td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui.table>
    </section>
</div>
