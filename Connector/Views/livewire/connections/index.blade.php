<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('People Connections')"
        :subtitle="__('Provider compatibility, capability, and freshness status.')"
    />

    @if ($configuredProviderId !== null && $activeProviderId === null)
        <x-ui.alert variant="warning">
            {{ __('The configured People provider :provider is unavailable. Connector-owned capabilities remain disconnected; install or repair that adapter before continuing.', ['provider' => $configuredProviderId]) }}
        </x-ui.alert>
    @elseif ($configuredProviderId === null)
        <x-ui.alert variant="info">
            {{ __('No People provider is configured. Connector-owned capabilities remain safely disconnected until a conforming adapter is installed and selected.') }}
        </x-ui.alert>
    @endif

    @if ($providers !== [])
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('Provider') }}</x-ui.th>
                    <x-ui.th>{{ __('Contract') }}</x-ui.th>
                    <x-ui.th>{{ __('Placement') }}</x-ui.th>
                    <x-ui.th>{{ __('Health') }}</x-ui.th>
                    <x-ui.th>{{ __('Capabilities') }}</x-ui.th>
                </tr>
            </x-slot:head>
            <x-slot:body>
                @foreach ($providers as $provider)
                    <tr wire:key="provider-{{ $provider['descriptor']->id }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            <span class="font-medium">{{ $provider['descriptor']->name }}</span>
                            <span class="block text-muted">{{ $provider['descriptor']->id }}</span>
                            @if ($activeProviderId === $provider['descriptor']->id)
                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">
                            {{ $provider['descriptor']->contractVersion }}
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            {{ __($provider['descriptor']->placement) }}
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            <x-ui.badge :variant="match ($provider['health']->state->value) { 'healthy' => 'success', 'unavailable' => 'danger', default => 'warning' }">
                                {{ __($provider['health']->state->value) }}
                            </x-ui.badge>
                            @if ($provider['health']->checkedAt !== null)
                                <span class="mt-1 block text-xs text-muted">
                                    {{ __('Checked') }} <x-ui.datetime :value="$provider['health']->checkedAt" />
                                </span>
                            @endif
                            @if ($provider['health']->lastSuccessfulSyncAt !== null)
                                <span class="mt-1 block text-xs text-muted">
                                    {{ __('Last successful sync') }} <x-ui.datetime :value="$provider['health']->lastSuccessfulSyncAt" />
                                </span>
                            @endif
                            @if ($provider['health']->message !== null)
                                <span class="mt-1 block text-xs text-muted">{{ $provider['health']->message }}</span>
                            @endif
                            <x-ui.button
                                type="button"
                                size="sm"
                                variant="secondary"
                                class="mt-2"
                                wire:click="refreshHealth('{{ $provider['descriptor']->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="refreshHealth('{{ $provider['descriptor']->id }}')"
                            >
                                {{ __('Check health') }}
                            </x-ui.button>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">
                            {{ count($provider['capabilities']) }}
                        </td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui.table>
    @endif

    @if ($reconciliationConnections->isNotEmpty())
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('Connection') }}</x-ui.th>
                    <x-ui.th>{{ __('Scope') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </tr>
            </x-slot:head>
            <x-slot:body>
                @foreach ($reconciliationConnections as $connection)
                    <tr wire:key="reconciliation-connection-{{ $connection->id }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            <span class="font-medium">{{ $connection->provider_id }}</span>
                            <span class="block text-muted">{{ $connection->label ?? $connection->provider_id }}</span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $connection->scope_key }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right">
                            <x-ui.button as="a" size="sm" variant="secondary" href="{{ route('admin.people-connector.reconciliation.index', $connection->id) }}" wire:navigate>
                                {{ __('Open reconciliation queue') }}
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui.table>
    @endif
</div>
