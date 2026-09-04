<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Reconciliation queue')"
        :subtitle="__('Reviewed identity decisions for this People provider connection.')"
    />

    <x-ui.alert :variant="$freshness->isStale() ? 'warning' : 'success'">
        @if ($freshness->isStale())
            {{ __('Workforce projections are stale: :reason.', ['reason' => __($freshness->staleReason)]) }}
        @else
            {{ __('Workforce projections are current as of :time.', ['time' => $freshness->asOf?->format('Y-m-d H:i T')]) }}
        @endif
    </x-ui.alert>

    <div class="text-sm text-muted">
        {{ __('Connection') }} <span class="font-medium text-ink">{{ $connection->provider_id }}</span>
        <span class="mx-1" aria-hidden="true">·</span>
        {{ $connection->scope_key }}
    </div>

    @if ($issues->isEmpty())
        <x-ui.alert variant="info">{{ __('There are no open reconciliation issues for this connection.') }}</x-ui.alert>
    @else
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('Issue') }}</x-ui.th>
                    <x-ui.th>{{ __('Record') }}</x-ui.th>
                    <x-ui.th>{{ __('Observed') }}</x-ui.th>
                    <x-ui.th>{{ __('Decision') }}</x-ui.th>
                </tr>
            </x-slot:head>
            <x-slot:body>
                @foreach ($issues as $issue)
                    <tr wire:key="reconciliation-issue-{{ $issue->id }}">
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                            <x-ui.badge :variant="match ($issue->severity) { 'error' => 'danger', 'warning' => 'warning', default => 'secondary' }">
                                {{ __($issue->severity) }}
                            </x-ui.badge>
                            <span class="mt-1 block font-medium">{{ __($issue->kind) }}</span>
                            <span class="block text-xs text-muted">{{ $issue->details['reason_code'] ?? __('No reason code') }}</span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                            <span class="font-medium">{{ $issue->resource_type ?? __('Connection') }}</span>
                            @if ($issue->external_id !== null)
                                <span class="block break-all text-muted">{{ $issue->external_id }}</span>
                            @endif
                            @if (isset($issue->details['related_external_id']))
                                <span class="mt-1 block break-all text-xs text-muted">
                                    {{ __('Survives as') }} {{ $issue->details['related_external_id'] }}
                                </span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                            <span class="block"><x-ui.datetime :value="$issue->first_seen_at" /></span>
                            <span class="mt-1 block text-xs text-muted"><x-ui.datetime :value="$issue->last_seen_at" /></span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                            @if ($issue->kind === 'sync_merge_requested')
                                @if (isset($issue->details['related_external_id']))
                                    <x-ui.input
                                        id="reconciliation-review-reference-{{ $issue->id }}"
                                        wire:model="reviewReferences.{{ $issue->id }}"
                                        :label="__('Review reference')"
                                        required
                                        :error="$errors->first('reviewReferences.'.$issue->id)"
                                    />
                                    <x-ui.button type="button" size="sm" class="mt-2" wire:click="applyMerge({{ $issue->id }})" wire:loading.attr="disabled">
                                        {{ __('Apply reviewed merge') }}
                                    </x-ui.button>
                                @else
                                    <x-ui.alert variant="warning">
                                        {{ __('This legacy merge issue is missing its surviving external reference and cannot be applied.') }}
                                    </x-ui.alert>
                                @endif
                            @elseif ($issue->resource_type !== null && $issue->external_id !== null)
                                <x-ui.input
                                    id="reconciliation-replacement-{{ $issue->id }}"
                                    wire:model="replacementExternalIds.{{ $issue->id }}"
                                    :label="__('Replacement external ID')"
                                    required
                                    :error="$errors->first('replacementExternalIds.'.$issue->id)"
                                />
                                <x-ui.input
                                    id="reconciliation-remap-review-reference-{{ $issue->id }}"
                                    wire:model="reviewReferences.{{ $issue->id }}"
                                    :label="__('Review reference')"
                                    required
                                    :error="$errors->first('reviewReferences.'.$issue->id)"
                                />
                                <x-ui.button type="button" size="sm" class="mt-2" wire:click="remapIdentity({{ $issue->id }})" wire:loading.attr="disabled">
                                    {{ __('Apply reviewed remap') }}
                                </x-ui.button>
                            @endif

                            <x-ui.textarea
                                id="reconciliation-resolution-note-{{ $issue->id }}"
                                wire:model="resolutionNotes.{{ $issue->id }}"
                                :label="__('Resolution note')"
                                rows="2"
                                required
                                :error="$errors->first('resolutionNotes.'.$issue->id)"
                            />
                            <x-ui.button type="button" size="sm" variant="secondary" class="mt-2" wire:click="resolveIssue({{ $issue->id }})" wire:loading.attr="disabled">
                                {{ __('Resolve with note') }}
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui.table>

        <div class="mt-section-gap">
            {{ $issues->links() }}
        </div>
    @endif
</div>
