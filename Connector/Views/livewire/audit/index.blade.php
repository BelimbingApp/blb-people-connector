<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Connection audit')"
        :subtitle="__('Operator actions recorded for connection :id (:provider, :scope), newest first.', ['id' => $connection->id, 'provider' => $connection->provider_id, 'scope' => $connection->scope_key])"
    />

    <x-ui.input id="audit-stream" :label="__('Stream')" wire:model.live.debounce.300ms="stream"
        :placeholder="__('All streams')" :help="__('Enter an exact stream name, or leave empty to show all activity.')" />

    @if ($rows->isEmpty())
        <x-ui.alert variant="info">
            {{ $stream === '' ? __('No operator action has been recorded for this connection.') : __('No activity matches this stream.') }}
        </x-ui.alert>
    @else
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('When') }}</x-ui.th>
                    <x-ui.th>{{ __('Operation') }}</x-ui.th>
                    <x-ui.th>{{ __('Actor') }}</x-ui.th>
                    <x-ui.th>{{ __('Connections') }}</x-ui.th>
                    <x-ui.th>{{ __('Reference') }}</x-ui.th>
                    <x-ui.th>{{ __('Before') }}</x-ui.th>
                    <x-ui.th>{{ __('After') }}</x-ui.th>
                </tr>
            </x-slot:head>
            <x-slot:body>
                @foreach ($rows as $row)
                    <tr wire:key="operator-audit-{{ $row->id }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row->occurred_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ __($row->operation->label()) }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row->actor_type }} #{{ $row->actor_id }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">
                            {{ $row->connection_id ?? '—' }}@if ($row->related_connection_id !== null) → {{ $row->related_connection_id }}@endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row->review_reference ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted"><code>{{ json_encode($row->before_summary, JSON_UNESCAPED_SLASHES) }}</code></td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted"><code>{{ json_encode($row->after_summary, JSON_UNESCAPED_SLASHES) }}</code></td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui.table>
    @endif
</div>
