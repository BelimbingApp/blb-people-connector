<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WorkforceFreshness;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * The explicit freshness decision connector features read before they act on
 * workforce state ([1006]). It looks at nothing but the durable checkpoint
 * and the connection status, so a feature cannot be told "fresh" by a pass
 * that wrote rows without ever completing.
 */
final class WorkforceFreshnessPolicy
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
        private SyncCheckpointStore $checkpoints,
    ) {}

    public function for(int $connectionId, ?\DateTimeImmutable $now = null): WorkforceFreshness
    {
        $this->tenantContext->requireTenantId();
        $connection = $this->connections->get($connectionId);
        $stream = self::stream();
        $maxAgeMinutes = self::maxAgeMinutes();
        $checkedAt = $now ?? \DateTimeImmutable::createFromInterface(now());
        $checkpoint = $this->checkpoints->current($connectionId, $stream);
        $asOf = $checkpoint?->as_of_at;

        $reason = match (true) {
            $connection->status !== ProviderConnection::STATUS_ACTIVE => WorkforceFreshness::REASON_CONNECTION_INACTIVE,
            $asOf === null => WorkforceFreshness::REASON_NEVER_SYNCHRONIZED,
            $checkedAt->getTimestamp() - $asOf->getTimestamp() > $maxAgeMinutes * 60 => WorkforceFreshness::REASON_EXCEEDED_MAX_AGE,
            default => null,
        };

        return new WorkforceFreshness(
            $connectionId,
            $stream,
            $checkedAt,
            $maxAgeMinutes,
            $asOf === null ? null : \DateTimeImmutable::createFromInterface($asOf),
            $reason,
        );
    }

    /** Fail closed: returns the decision only when the projections may be relied on. */
    public function assertFresh(int $connectionId, ?\DateTimeImmutable $now = null): WorkforceFreshness
    {
        $freshness = $this->for($connectionId, $now);
        $freshness->assertFresh();

        return $freshness;
    }

    public static function stream(): string
    {
        $stream = config('people-connector.sync.stream', 'workforce');

        if (! is_string($stream) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $stream) !== 1) {
            throw new \InvalidArgumentException('people-connector.sync.stream must be a stable lowercase identifier.');
        }

        return $stream;
    }

    public static function maxAgeMinutes(): int
    {
        $minutes = config('people-connector.sync.max_age_minutes', 1440);

        if (! is_int($minutes) || $minutes < 1) {
            throw new \InvalidArgumentException('people-connector.sync.max_age_minutes must be a positive integer.');
        }

        return $minutes;
    }
}
