<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;

final class ProviderPortResolver
{
    /**
     * @template TPort of ReadableProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @return TPort
     */
    public function read(
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
    ): ReadableProviderPort {
        if (! is_a($contract, ReadableProviderPort::class, true)) {
            throw new \InvalidArgumentException('Readable provider resolution requires a readable port interface.');
        }

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->readPortContracts($capability),
            'read',
        );
    }

    /**
     * @template TPort of WritableProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @return TPort
     */
    public function write(
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
    ): WritableProviderPort {
        if (! is_a($contract, WritableProviderPort::class, true)) {
            throw new \InvalidArgumentException('Writable provider resolution requires a writable port interface.');
        }

        /** @var TPort */
        return $this->resolve(
            $provider,
            $capability,
            $contract,
            $provider->capabilities()->writePortContracts($capability),
            'write',
        );
    }

    /**
     * @template TPort of ProviderPort
     *
     * @param  class-string<TPort>  $contract
     * @param  list<class-string>  $declaredContracts
     * @return TPort
     */
    private function resolve(
        ProviderAdapter $provider,
        PeopleCapability $capability,
        string $contract,
        array $declaredContracts,
        string $direction,
    ): ProviderPort {
        $descriptor = $provider->descriptor();
        $context = [
            'capability' => $capability->value,
            'direction' => $direction,
            'port_contract' => $contract,
        ];

        if (! in_array($contract, $declaredContracts, true)) {
            throw new UnsupportedProviderOperation(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' does not support {$direction} access for capability '{$capability->value}' through {$contract}.",
                context: $context,
            );
        }

        $port = $provider->resolvePort($contract);

        if (! $port instanceof $contract) {
            throw new ProviderCompatibilityException(
                providerId: $descriptor->id,
                operation: "resolve_{$direction}_port",
                message: "Provider '{$descriptor->id}' declares {$contract} but cannot resolve a compatible implementation.",
                context: $context,
            );
        }

        return $port;
    }
}
