<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Illuminate\Support\Facades\DB;

final class ProviderConnectionStore
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
    ) {}

    public function configure(
        ProviderScope $scope,
        string $providerId,
        ?ProviderConnectionMetadata $publicMetadata = null,
        ?string $label = null,
        ?string $adapterVersion = null,
        ?string $contractVersion = null,
    ): ProviderConnection {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertProviderId($providerId);
        $this->assertCompanyScope($scope, $tenantId);

        if (($label !== null && strlen($label) > 255)
            || ($adapterVersion !== null && strlen($adapterVersion) > 50)
            || ($contractVersion !== null && strlen($contractVersion) > 50)) {
            throw new InvalidProviderConfigurationException('Provider connection metadata exceeds its supported length.');
        }

        return DB::transaction(function () use ($scope, $providerId, $publicMetadata, $label, $adapterVersion, $contractVersion, $tenantId): ProviderConnection {
            $connection = ProviderConnection::query()
                ->forTenant($tenantId)
                ->where('scope_key', $scope->key())
                ->where('provider_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = new ProviderConnection([
                    'tenant_id' => $tenantId,
                    'company_id' => $scope->companyId,
                    'scope_key' => $scope->key(),
                    'provider_id' => $providerId,
                    'status' => ProviderConnection::STATUS_INACTIVE,
                ]);
            }

            $connection->fill([
                'label' => $label,
                'adapter_version' => $adapterVersion,
                'contract_version' => $contractVersion,
                'public_metadata' => $publicMetadata?->toArray(),
            ]);
            $connection->save();

            return $connection->refresh();
        });
    }

    public function activate(int $connectionId): ProviderConnection
    {
        $target = $this->connections->get($connectionId);

        return DB::transaction(function () use ($connectionId, $target): ProviderConnection {
            $scopeConnections = ProviderConnection::query()
                ->forTenant((int) $target->tenant_id)
                ->where('scope_key', $target->scope_key)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $connection = $scopeConnections->firstWhere('id', $connectionId)
                ?? throw new InvalidProviderConfigurationException(
                    'The provider connection scope changed while activation was in progress.',
                );

            if ($connection->status === ProviderConnection::STATUS_ACTIVE) {
                return $connection;
            }

            $now = now();

            $scopeConnections
                ->where('status', ProviderConnection::STATUS_ACTIVE)
                ->each(function (ProviderConnection $active) use ($now): void {
                    $active->fill([
                        'status' => ProviderConnection::STATUS_INACTIVE,
                        'deactivated_at' => $now,
                    ])->save();
                });

            $connection->fill([
                'status' => ProviderConnection::STATUS_ACTIVE,
                'activated_at' => $now,
                'deactivated_at' => null,
            ])->save();

            return $connection->refresh();
        });
    }

    public function active(ProviderScope $scope): ?ProviderConnection
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertCompanyScope($scope, $tenantId);

        return ProviderConnection::query()
            ->forTenant($tenantId)
            ->where('active_scope_key', $scope->key())
            ->first();
    }

    public function find(int $connectionId): ProviderConnection
    {
        return $this->connections->get($connectionId);
    }

    private function assertProviderId(string $providerId): void
    {
        if (strlen($providerId) > 100
            || preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $providerId) !== 1) {
            throw new InvalidProviderConfigurationException('Provider connections require a stable lowercase provider ID.');
        }
    }

    private function assertCompanyScope(ProviderScope $scope, int $tenantId): void
    {
        if ($scope->companyId === null) {
            return;
        }

        if (! Company::query()->forTenant($tenantId)->whereKey($scope->companyId)->exists()) {
            throw new InvalidProviderConfigurationException('The provider company scope does not belong to the current tenant.');
        }
    }
}
