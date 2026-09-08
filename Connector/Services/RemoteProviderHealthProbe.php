<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Health for a connection whose People installation runs somewhere else (#310).
 *
 * The reason this exists: ConnectionHealthChecker pings the connection's
 * adapter in this process, and FirstPartyPeopleAdapter::health() returns
 * Healthy by construction. For a remote_http connection that answer describes
 * the wrong host — the local adapter is fine while the remote installation is
 * down. Reporting Healthy there is worse than reporting nothing, because an
 * operator acts on it.
 *
 * States are mapped deliberately:
 *
 *   Unavailable — unreachable, timed out, or answered non-2xx. The connector
 *                 cannot see the remote host, so it says so.
 *   Degraded    — reachable and answering, but speaking a contract major this
 *                 connector does not support. The host is up and the pairing is
 *                 wrong, which is a different repair from a dead host.
 *   Healthy     — reachable, 2xx, contract major matches.
 *
 * #310 names this state `Disconnected`. ProviderHealthState has no such case
 * and already carries `Unavailable` for exactly "cannot be reached"; adding a
 * second word for one state would leave two vocabularies in one report. The
 * issue's own prose asks for an honest disconnected state rather than a
 * particular identifier, so this uses the one that exists.
 *
 * No exception text ever reaches the report. docs/contracts/diagnostic-privacy.md
 * requires persisted and reported failures to be reason codes, and a transport
 * exception carries the base URL, headers and sometimes the credential.
 */
final class RemoteProviderHealthProbe
{
    /**
     * The audience and scope the remote People host is asked for. Both are
     * narrow on purpose: a health read needs to prove the connection is
     * authenticated, not to carry directory scope it will never exercise.
     */
    public const AUDIENCE = 'blb-people-connector';

    public const SCOPES = ['employee_directory:read'];

    public function __construct(
        private readonly ProviderCredentialStore $credentials,
    ) {}

    public function handles(ProviderConnection $connection): bool
    {
        return $connection->mode === ProviderConnectionMode::RemoteHttp;
    }

    public function probe(ProviderConnection $connection, ?DateTimeImmutable $at = null): ProviderHealth
    {
        $at ??= new DateTimeImmutable;

        $baseUrl = is_string($connection->remote_base_url) ? trim($connection->remote_base_url) : '';
        if ($baseUrl === '') {
            return new ProviderHealth(ProviderHealthState::Unavailable, $at, null, 'remote_base_url_missing');
        }

        $path = (string) config('people-connector.remote.health_path', '/health');
        $timeout = (int) config('people-connector.remote.timeout_seconds', 5);

        try {
            $request = Http::timeout($timeout)->acceptJson();

            // The credential is connection-scoped: #30 requires remote
            // deployments to use separately scoped credentials, so this never
            // falls back to an ambient or shared token. A connection with no
            // usable credential is unauthenticated against the remote host and
            // is reported unavailable rather than probed anonymously.
            $credential = $this->credential($connection, $at);
            if ($credential === null) {
                return new ProviderHealth(ProviderHealthState::Unavailable, $at, null, 'remote_credential_unusable');
            }
            $request = $request->withToken($credential);

            $response = $request->get(rtrim($baseUrl, '/').$path);
        } catch (Throwable) {
            return new ProviderHealth(ProviderHealthState::Unavailable, $at, null, 'remote_unreachable');
        }

        if (! $response->successful()) {
            return new ProviderHealth(ProviderHealthState::Unavailable, $at, null, 'remote_status_'.$response->status());
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $supportedMajor = (int) config('people-connector.supported_contract_major', 1);
        $remoteMajor = $this->contractMajor($payload);
        if ($remoteMajor === null) {
            return new ProviderHealth(ProviderHealthState::Degraded, $at, null, 'remote_contract_unknown');
        }
        if ($remoteMajor !== $supportedMajor) {
            // Both majors are named: "incompatible" without the pair is a
            // finding an operator cannot act on.
            return new ProviderHealth(
                ProviderHealthState::Degraded,
                $at,
                null,
                'remote_contract_major_mismatch_remote_'.$remoteMajor.'_supported_'.$supportedMajor,
            );
        }

        // The remote reports its own watermark; the threshold is the one the
        // rest of the connector already uses, read from WorkforceFreshnessPolicy
        // rather than re-read from config here. WorkforceFreshnessPolicy itself
        // judges the *local* checkpoint and is the wrong instrument for a
        // number the far side supplied, but the age rule is the same rule and
        // should not exist twice.
        $observedAt = $this->observedAt($payload);
        if ($observedAt !== null
            && $at->getTimestamp() - $observedAt->getTimestamp() > WorkforceFreshnessPolicy::maxAgeMinutes() * 60) {
            return new ProviderHealth(ProviderHealthState::Degraded, $at, $observedAt, 'remote_workforce_stale');
        }

        return new ProviderHealth(ProviderHealthState::Healthy, $at, $observedAt);
    }

    /**
     * The connection-scoped credential, vended by ProviderCredentialStore so
     * expiry, revocation and the tenant boundary are enforced in one place
     * rather than re-implemented here.
     *
     * What goes on the wire is the key id. `ProviderCredential` carries
     * identifiers only and never a secret -- deliberately, so a credential can
     * be logged and reported without leaking one. The secret-bearing exchange a
     * real remote transport needs does not exist yet; that is the same gap that
     * makes WorkforceSyncRunner refuse a remote pass, and it is why this
     * topology is documented as not activation-ready. Until it lands, this
     * proves a usable credential exists and identifies which one, which is what
     * lets the health path refuse honestly instead of probing anonymously.
     */
    private function credential(ProviderConnection $connection, DateTimeImmutable $at): ?string
    {
        if ($connection->remote_credential_id === null) {
            return null;
        }

        try {
            $credential = $this->credentials->requireUsable(
                new ProviderAuthenticationRequest(
                    (int) $connection->tenant_id,
                    (int) $connection->id,
                    self::AUDIENCE,
                    self::SCOPES,
                ),
                $at,
            );
        } catch (Throwable) {
            return null;
        }

        return $credential->keyId;
    }

    private function contractMajor(array $payload): ?int
    {
        foreach (['contract_major', 'contractMajor'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        foreach (['contract_version', 'contractVersion'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && preg_match('/^(\d+)/', $value, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    private function observedAt(array $payload): ?DateTimeImmutable
    {
        foreach (['observed_at', 'observedAt', 'as_of', 'asOf'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    return new DateTimeImmutable($value);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }
}
