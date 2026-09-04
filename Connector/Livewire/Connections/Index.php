<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Connections;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthMonitor;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    public function render(
        ProviderRegistry $registry,
        ProviderHealthStore $healthStore,
        AuthorizationService $authorization,
        CompanyAttribution $attribution,
    ): View {
        $active = $registry->active();
        $user = Auth::user();
        $canManageIdentities = $user instanceof User
            && $authorization->can(Actor::forUser($user), 'people-connector.identity.manage')->allowed;

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
            'reconciliationConnections' => $canManageIdentities
                ? ProviderConnection::query()
                    ->forTenant($user->tenant_id)
                    ->get()
                    ->filter(fn (ProviderConnection $connection): bool => $attribution->mayActForConnection($user, $connection))
                    ->values()
                : collect(),
        ]);
    }
}
