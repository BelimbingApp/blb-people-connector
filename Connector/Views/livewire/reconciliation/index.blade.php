<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Reconciliation queue')"
        :subtitle="__('Reviewed identity decisions for this People provider connection.')"
    />

    <x-ui.alert :variant="$freshness->isStale() ? 'warning' : 'success'">
        @if ($freshness->isStale())
            {{ __('Workforce projections are stale: :reason.', ['reason' => __($freshness->staleReason)]) }}
        @else
            {{ __('Workforce projections are current as of') }} <x-ui.datetime :value="$freshness->asOf" />.
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
                                {{ match ($issue->severity) { 'error' => __('Needs attention'), 'warning' => __('Review needed'), default => __('Information') } }}
                            </x-ui.badge>
                            <span class="mt-1 block font-medium">{{ match ($issue->kind) { 'sync_merge_requested' => __('Merge review'), 'sync_conflict' => __('Synchronization conflict'), 'sync_feed_refused' => __('Synchronization refused'), 'sync_empty_bootstrap' => __('Empty initial synchronization'), 'sync_unknown_outcome' => __('Unconfirmed command outcome'), 'sync_stale' => __('Stale synchronization'), default => __('Reconciliation issue') } }}</span>
                            <span class="block text-xs text-muted">{{ match ($issue->details['reason_code'] ?? null) { 'review_required' => __('A human decision is required.'), 'projection_conflict' => __('The provider facts conflict with the current projection.'), 'record_not_found' => __('The provider record has no known identity.'), 'every_record_refused' => __('Every record in this synchronization was refused.'), 'no_records' => __('The provider reported no records.'), 'not_sent' => __('The command was never sent to the provider.'), 'answer_lost' => __('The command was sent but the provider answer was lost.'), 'provider_refused' => __('The provider refused the command.'), 'absent_at_provider' => __('The provider has no record of this command.'), 'exceeded_max_age' => __('The provider watermark is older than the maximum age.'), 'never_synchronized' => __('This connection has never completed a synchronization.'), default => __('Review the recorded evidence.') } }}</span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                            <span class="font-medium">{{ $issue->resource_type ?? ($issue->kind === 'sync_unknown_outcome' ? __('Command') : __('Connection')) }}</span>
                            @if ($issue->external_id !== null)
                                <span class="block break-all text-muted">{{ $issue->external_id }}</span>
                            @elseif ($issue->kind === 'sync_unknown_outcome')
                                <span class="block break-all text-muted">{{ $issue->issue_key }}</span>
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
                            @elseif ($issue->kind === 'sync_unknown_outcome')
                                <x-ui.select
                                    id="reconciliation-command-resolution-{{ $issue->id }}"
                                    wire:model="commandResolutions.{{ $issue->id }}"
                                    :label="__('Confirmed outcome')"
                                    required
                                    :error="$errors->first('commandResolutions.'.$issue->id)"
                                >
                                    <option value="">{{ __('Select what the provider did') }}</option>
                                    <option value="confirmed_delivered">{{ __('Confirmed delivered') }}</option>
                                    <option value="confirmed_not_delivered">{{ __('Confirmed not delivered') }}</option>
                                </x-ui.select>
                                <x-ui.input
                                    id="reconciliation-command-review-reference-{{ $issue->id }}"
                                    wire:model="reviewReferences.{{ $issue->id }}"
                                    :label="__('Review reference')"
                                    required
                                    :error="$errors->first('reviewReferences.'.$issue->id)"
                                />
                                <p class="mt-1 text-xs text-muted">{{ __('Confirming records what the provider did. It does not resend the command.') }}</p>
                                <x-ui.button type="button" size="sm" class="mt-2" wire:click="confirmUnknownOutcome({{ $issue->id }})" wire:loading.attr="disabled">
                                    {{ __('Confirm command outcome') }}
                                </x-ui.button>
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
