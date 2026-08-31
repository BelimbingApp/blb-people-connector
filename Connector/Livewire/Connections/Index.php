<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Connections;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthMonitor;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public function refreshHealth(
        string $providerId,
        ProviderRegistry $registry,
        ProviderHealthMonitor $monitor,
    ): void {
        $provider = $registry->find($providerId);

        if ($provider !== null) {
            $monitor->refresh($provider);
        }
    }

    public function render(ProviderRegistry $registry, ProviderHealthStore $healthStore): View
    {
        $active = $registry->active();

        return view('people-connector::livewire.connections.index', [
            'activeProviderId' => $active?->descriptor()->id,
            'configuredProviderId' => $registry->configuredProviderId(),
            'providers' => array_map(
                static fn (ProviderAdapter $provider): array => [
                    'descriptor' => $provider->descriptor(),
                    'capabilities' => $provider->capabilities()->all(),
                    'health' => $healthStore->snapshot($provider->descriptor()->id),
                ],
                $registry->all(),
            ),
        ]);
    }
}
