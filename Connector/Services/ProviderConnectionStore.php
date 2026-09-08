<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\PeopleConnector\Connector\Data\ProviderConnectionMetadata;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WebhookDeliveryPolicy;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
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
        ProviderConnectionMode $mode = ProviderConnectionMode::InProcess,
        ?string $remoteBaseUrl = null,
        ?int $remoteCredentialId = null,
    ): ProviderConnection {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertProviderId($providerId);
        $this->assertCompanyScope($scope, $tenantId);
        $remoteBaseUrl = $this->assertPlacement($mode, $remoteBaseUrl, $remoteCredentialId);

        if (($label !== null && strlen($label) > 255)
            || ($adapterVersion !== null && strlen($adapterVersion) > 50)
            || ($contractVersion !== null && strlen($contractVersion) > 50)) {
            throw new InvalidProviderConfigurationException('Provider connection metadata exceeds its supported length.');
        }

        return DB::transaction(function () use ($scope, $providerId, $publicMetadata, $label, $adapterVersion, $contractVersion, $tenantId, $mode, $remoteBaseUrl, $remoteCredentialId): ProviderConnection {
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

            // One row exists per (tenant, scope, provider), so this finds a
            // retired connection rather than making a second one. Rewriting its
            // label or versions would edit history that retirement froze.
            ConnectionRetirementService::assertWritable($connection);

            $metadata = $publicMetadata?->toArray() ?? [];
            $metadata['webhook_delivery_policy'] ??= WebhookDeliveryPolicy::defaults()->toArray();

            // The credential must already belong to this connection. Checking it
            // here rather than at use is what lets the operator be told which
            // connection owns it; a reference resolved later just fails a probe
            // with nothing to act on. A connection being created for the first
            // time has no id yet, so it cannot own a credential either, and
            // naming one is refused rather than silently stored.
            if ($remoteCredentialId !== null) {
                $owned = ProviderCredentialRecord::query()
                    ->forTenant($tenantId)
                    ->whereKey($remoteCredentialId)
                    ->where('connection_id', $connection->exists ? (int) $connection->id : 0)
                    ->exists();
                if (! $owned) {
                    throw new InvalidProviderConfigurationException(
                        'A remote provider credential must belong to the connection it is configured on, inside the same tenant.',
                    );
                }
            }

            $connection->fill([
                'label' => $label,
                'adapter_version' => $adapterVersion,
                'contract_version' => $contractVersion,
                'public_metadata' => $metadata,
                'mode' => $mode,
                'remote_base_url' => $remoteBaseUrl,
                'remote_credential_id' => $remoteCredentialId,
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

            // Retirement that a single activate() undoes is not retirement.
            // Coming back means configuring a new connection, which carries its
            // own provider-replacement decision.
            ConnectionRetirementService::assertWritable($connection);

            if ($connection->status === ProviderConnection::STATUS_ACTIVE) {
                app(SchedulerPrincipalGrants::class)->grant($connection);

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
                    app(SchedulerPrincipalGrants::class)->revoke($active);
                });

            $connection->fill([
                'status' => ProviderConnection::STATUS_ACTIVE,
                'activated_at' => $now,
                'deactivated_at' => null,
            ])->save();

            app(SchedulerPrincipalGrants::class)->grant($connection->refresh());

            return $connection;
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

    /**
     * Placement validation, before anything is written.
     *
     * A non-remote connection carrying a base URL or a credential is refused
     * rather than quietly cleared: silently dropping them would leave the
     * operator believing a remote placement had been configured.
     */
    private function assertPlacement(ProviderConnectionMode $mode, ?string $remoteBaseUrl, ?int $remoteCredentialId): ?string
    {
        if ($mode !== ProviderConnectionMode::RemoteHttp) {
            if ($remoteBaseUrl !== null || $remoteCredentialId !== null) {
                throw new InvalidProviderConfigurationException(
                    'A remote base URL and credential belong only to a remote_http provider connection.',
                );
            }

            return null;
        }

        if ($remoteBaseUrl === null || trim($remoteBaseUrl) === '') {
            throw new InvalidProviderConfigurationException(
                'A remote_http provider connection requires the base URL of the People installation it reaches.',
            );
        }

        $remoteBaseUrl = rtrim(trim($remoteBaseUrl), '/');
        if (strlen($remoteBaseUrl) > 500) {
            throw new InvalidProviderConfigurationException('Provider connection metadata exceeds its supported length.');
        }

        $parts = parse_url($remoteBaseUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if ($host === null || $host === '' || $scheme !== 'https') {
            throw new InvalidProviderConfigurationException(
                'A remote_http provider connection requires an absolute https base URL with a host.',
            );
        }

        // The allowlist is the control, and it is compared against the parsed
        // host only. Matching on the whole URL would let
        // https://allowed.example@attacker.example pass a substring test while
        // the request went somewhere else entirely.
        $allowed = array_map(
            static fn (mixed $entry): string => strtolower(trim((string) $entry)),
            (array) config('people-connector.remote.allowed_hosts', []),
        );
        if (! in_array(strtolower($host), $allowed, true)) {
            throw new InvalidProviderConfigurationException(
                'The remote People host is not in people-connector.remote.allowed_hosts.',
            );
        }

        return $remoteBaseUrl;
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
