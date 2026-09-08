<?php

namespace App\Domains\PeopleConnector\Connector\Services;

/** Credential-free DNS, TCP, and TLS reachability primitives. */
final class EgressSocketProbe
{
    public const TIMEOUT_SECONDS = 5;

    public function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4) && $ipv4 !== []) {
            return true;
        }

        $ipv6 = dns_get_record($host, DNS_AAAA);

        return is_array($ipv6) && $ipv6 !== [];
    }

    public function connects(string $host, int $port, bool $tls): bool
    {
        $authority = str_contains($host, ':') ? "[{$host}]" : $host;
        $context = stream_context_create($tls ? [
            'ssl' => [
                // This command proves negotiation reachability, not provider
                // identity. The authenticated health check owns trust/auth.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ] : []);
        $socket = @stream_socket_client(
            "tcp://{$authority}:{$port}",
            $errorNumber,
            $error,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return false;
        }

        $connected = ! $tls || $this->enablesTls($socket);
        fclose($socket);

        return $connected;
    }

    /** @param resource $socket */
    private function enablesTls($socket): bool
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        do {
            $enabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($enabled === true) {
                return true;
            }
            if ($enabled === false) {
                return false;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }

            $read = [$socket];
            $write = [$socket];
            $except = [];
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            @stream_select($read, $write, $except, $seconds, $microseconds);
        } while (true);
    }
}
