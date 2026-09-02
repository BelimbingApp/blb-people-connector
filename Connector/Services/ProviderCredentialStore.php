<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderCredential;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthenticationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ProviderCredentialRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class ProviderCredentialStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantConnectionLocator $connections,
    ) {}

    public function issue(
        ProviderAuthenticationRequest $request,
        ProviderConnection $connection,
        string $keyId,
        string $secretReference,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): ProviderCredential {
        $tenantId = $this->tenantContext->requireTenantId();
        $connection = $this->activeConnection($request, $connection, $tenantId);
        $securityNow = new DateTimeImmutable(now()->toISOString());

        if ((string) $connection->provider_id === ''
            || $issuedAt > $securityNow->modify('+5 seconds')
            || $expiresAt > $securityNow->modify('+305 seconds')) {
            throw new ProviderAuthenticationException(
                providerId: (string) $connection->provider_id,
                operation: 'issue_credential',
                message: 'Provider credentials require a current connection and a server-bounded issuance window.',
            );
        }

        if ($keyId === '' || preg_match('/^base-integration:[a-z0-9._:-]+$/', $secretReference) !== 1) {
            throw new ProviderAuthenticationException(
                providerId: (string) $connection->provider_id,
                operation: 'issue_credential',
                message: 'Provider credentials require a Base Integration secret reference, never a raw secret.',
            );
        }

        $credentialId = 'pcred_'.str()->lower(str()->random(26));
        $publicCredential = new ProviderCredential(
            credentialId: $credentialId,
            keyId: $keyId,
            providerId: (string) $connection->provider_id,
            audience: $request->audience,
            scopes: $request->scopes,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );
        $credential = new ProviderCredentialRecord([
            'tenant_id' => $tenantId,
            'connection_id' => $connection->id,
            'provider_id' => $connection->provider_id,
            'credential_id' => $credentialId,
            'key_id' => $keyId,
            'secret_reference' => $secretReference,
            'audience' => $request->audience,
            'scopes' => $request->scopes,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);
        $credential->save();

        return $publicCredential;
    }

    public function rotate(
        ProviderAuthenticationRequest $request,
        ProviderConnection $connection,
        string $keyId,
        string $secretReference,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): ProviderCredential {
        return DB::transaction(function () use ($request, $connection, $keyId, $secretReference, $issuedAt, $expiresAt): ProviderCredential {
            $connection = $this->activeConnection($request, $connection, $this->tenantContext->requireTenantId(), true);
            ProviderCredentialRecord::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->where('connection_id', $connection->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $issuedAt]);

            return $this->issue($request, $connection, $keyId, $secretReference, $issuedAt, $expiresAt);
        });
    }

    public function revoke(string $credentialId, DateTimeImmutable $revokedAt): void
    {
        DB::transaction(function () use ($credentialId, $revokedAt): void {
            $tenantId = $this->tenantContext->requireTenantId();
            $credential = ProviderCredentialRecord::query()
                ->withoutCompanyScope('Credential revocation first resolves the credential owner connection.')
                ->forTenant($tenantId)
                ->where('credential_id', $credentialId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($credential === null) {
                throw new ProviderAuthenticationException(
                    providerId: 'unknown',
                    operation: 'revoke_credential',
                    message: 'The provider credential is missing, already revoked, or outside the current tenant.',
                );
            }

            // Resolve the connection through the tenant boundary even when it is
            // already inactive: revocation must remain possible during teardown.
            $this->connections->get((int) $credential->connection_id, true);
            $credential->forceFill(['revoked_at' => $revokedAt])->save();
        });
    }

    public function requireUsable(ProviderAuthenticationRequest $request, DateTimeImmutable $at): ProviderCredential
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($request->tenantId !== $tenantId) {
            throw new ProviderAuthenticationException(
                providerId: 'unknown',
                operation: 'resolve_credential',
                message: 'Provider credential requests cannot cross the current tenant boundary.',
            );
        }

        try {
            $connection = $this->connections->get($request->connectionId);
        } catch (ConnectorRecordNotFoundException) {
            throw new ProviderAuthenticationException(
                providerId: 'unknown',
                operation: 'resolve_credential',
                message: 'The provider connection is missing or outside the current tenant.',
            );
        }

        if ($connection->status !== ProviderConnection::STATUS_ACTIVE) {
            throw new ProviderAuthenticationException(
                providerId: (string) $connection->provider_id,
                operation: 'resolve_credential',
                message: 'Provider credentials cannot be resolved for an inactive connection.',
            );
        }

        $securityAt = new DateTimeImmutable(now()->toISOString());

        $credential = ProviderCredentialRecord::query()
            ->forTenant($tenantId)
            ->where('connection_id', $request->connectionId)
            ->usable($request->audience, $request->scopes[0] ?? '', $securityAt)
            ->latest('issued_at')
            ->first();

        if ($credential === null) {
            throw new ProviderAuthenticationException(
                providerId: 'unknown',
                operation: 'resolve_credential',
                message: 'No active provider credential satisfies the requested audience, scope, and lifetime.',
            );
        }

        $result = $credential->toCredential();
        foreach ($request->scopes as $scope) {
            if (! $result->allows($request->audience, $scope, $securityAt)) {
                throw new ProviderAuthenticationException(
                    providerId: $result->providerId,
                    operation: 'resolve_credential',
                    message: 'The provider credential does not authorize every requested scope.',
                );
            }
        }

        return $result;
    }

    private function activeConnection(
        ProviderAuthenticationRequest $request,
        ProviderConnection $suppliedConnection,
        int $tenantId,
        bool $forUpdate = false,
    ): ProviderConnection {
        try {
            $connection = $this->connections->get($request->connectionId, $forUpdate);
        } catch (ConnectorRecordNotFoundException) {
            throw new ProviderAuthenticationException(
                providerId: (string) $suppliedConnection->provider_id,
                operation: 'issue_credential',
                message: 'Provider credentials require a connection inside the current tenant.',
            );
        }

        if ($request->tenantId !== $tenantId
            || (int) $connection->tenant_id !== $tenantId
            || $connection->status !== ProviderConnection::STATUS_ACTIVE
            || (string) $connection->provider_id !== (string) $suppliedConnection->provider_id) {
            throw new ProviderAuthenticationException(
                providerId: (string) $connection->provider_id,
                operation: 'issue_credential',
                message: 'Provider credentials cannot cross the current tenant or connection boundary.',
            );
        }

        return $connection;
    }
}
