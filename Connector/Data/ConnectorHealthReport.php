<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What the composed People installation looks like from the inside: which
 * adapter is actually wired in, what it says it can do, whether the contract it
 * speaks is one this connector understands, and how fresh each connection is.
 *
 * Everything awkward is reported rather than thrown. No adapter registered, or
 * an adapter speaking a contract major this connector does not support, are the
 * findings an operator opened this read to get; refusing on them would hide the
 * answer behind the question.
 */
final readonly class ConnectorHealthReport
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<ConnectorConnectionHealth>  $connections
     */
    public function __construct(
        public int $tenantId,
        /** What config names as the active provider, whether or not it registered. */
        public ?string $configuredAdapterId,
        /** The adapter actually available under that name; null when it never registered. */
        public ?string $adapterId,
        public ?string $adapterName,
        public ?string $adapterVersion,
        public ?string $contractVersion,
        public bool $contractCompatible,
        public int $supportedContractMajor,
        public array $capabilities,
        public array $connections,
    ) {}

    public function hasActiveAdapter(): bool
    {
        return $this->adapterId !== null;
    }
}
