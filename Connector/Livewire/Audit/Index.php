<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Audit;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Read-only operator listing of the audit rows for one connection (#199):
 * every retirement, replacement, rehearsal and purge that named it, newest
 * first. The connection is resolved through the tenant-scoped locator, so a
 * connection of another tenant is not found rather than listed.
 */
final class Index extends Component
{
    public int $connectionId;

    public function mount(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        $this->authorizeConnection();
    }

    public function render(): View
    {
        $this->authorizeConnection();
        $connection = $this->connection();

        $rows = OperatorAudit::query()
            ->forTenant((int) $connection->tenant_id)
            ->where(function ($query) use ($connection): void {
                $query->where('connection_id', $connection->id)
                    ->orWhere('related_connection_id', $connection->id);
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('people-connector::livewire.audit.index', [
            'connection' => $connection,
            'rows' => $rows,
        ]);
    }

    private function connection(): ProviderConnection
    {
        return app(TenantConnectionLocator::class)->get($this->connectionId);
    }

    private function authorizeConnection(): void
    {
        $user = Auth::user();
        $tenantId = app(TenantContext::class)->currentTenantId();
        abort_unless($user instanceof User && $tenantId !== null && $user->tenant_id === $tenantId, 403);
        app(AuthorizationService::class)->authorize(Actor::forUser($user), 'people-connector.connection.list');
        abort_unless(app(CompanyAttribution::class)->mayActForConnection($user, $this->connection()), 403);
    }
}
