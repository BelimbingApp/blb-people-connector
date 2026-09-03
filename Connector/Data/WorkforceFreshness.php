<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Exceptions\StaleWorkforceStateException;

/**
 * Whether a connection's workforce projections may be relied on right now.
 *
 * Age is measured from the provider's own as-of watermark on the durable
 * checkpoint, not from when the connector finished writing: a pass that ran
 * an hour ago over data the provider stamped a week ago is a week stale.
 */
final readonly class WorkforceFreshness
{
    public const REASON_NEVER_SYNCHRONIZED = 'never_synchronized';

    public const REASON_EXCEEDED_MAX_AGE = 'exceeded_max_age';

    public const REASON_CONNECTION_INACTIVE = 'connection_inactive';

    public function __construct(
        public int $connectionId,
        public string $stream,
        public \DateTimeImmutable $checkedAt,
        public int $maxAgeMinutes,
        public ?\DateTimeImmutable $asOf,
        public ?string $staleReason,
    ) {
        if ($maxAgeMinutes < 1) {
            throw new \InvalidArgumentException('Workforce freshness requires a positive maximum age in minutes.');
        }
        if ($staleReason !== null && ! in_array($staleReason, [
            self::REASON_NEVER_SYNCHRONIZED,
            self::REASON_EXCEEDED_MAX_AGE,
            self::REASON_CONNECTION_INACTIVE,
        ], true)) {
            throw new \InvalidArgumentException('Unknown workforce staleness reason.');
        }
    }

    public function isStale(): bool
    {
        return $this->staleReason !== null;
    }

    /** Minutes between the provider watermark and the check, or null when never synchronised. */
    public function ageMinutes(): ?int
    {
        if ($this->asOf === null) {
            return null;
        }

        return intdiv($this->checkedAt->getTimestamp() - $this->asOf->getTimestamp(), 60);
    }

    public function assertFresh(): void
    {
        if ($this->staleReason === null) {
            return;
        }

        throw new StaleWorkforceStateException(match ($this->staleReason) {
            self::REASON_NEVER_SYNCHRONIZED => "Workforce projections for connection {$this->connectionId} have never been synchronised.",
            self::REASON_CONNECTION_INACTIVE => "Provider connection {$this->connectionId} is inactive; its workforce projections cannot be relied on.",
            default => "Workforce projections for connection {$this->connectionId} are {$this->ageMinutes()} minutes old; the maximum is {$this->maxAgeMinutes}.",
        });
    }
}
