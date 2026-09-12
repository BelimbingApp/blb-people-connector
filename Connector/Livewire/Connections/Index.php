<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Connections;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
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
        $user = Auth::user();
        $tenantId = app(TenantContext::class)->currentTenantId();
        abort_unless($user instanceof User && $tenantId !== null && $user->tenant_id === $tenantId, 403);
        // A denial is an AuthorizationDeniedException, not an HTTP abort. The
        // surrounding guards here already speak in abort(403), and every People
        // Livewire component converts at the guard rather than leaving it to the
        // global renderer, so do the same and keep one refusal shape per screen.
        try {
            app(AuthorizationService::class)->authorize(Actor::forUser($user), 'people-connector.connection.list');
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

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
