<?php

namespace App\Domains\PeopleConnector\Training\Data;

final readonly class TrainingParticipationSummary
{
    public function __construct(
        public ?int $enrolled,
        public ?int $attended,
        public ?int $completed,
        public ?int $passed,
    ) {}

    public static function unavailable(): self
    {
        return new self(null, null, null, null);
    }

    public function isAvailable(): bool
    {
        return $this->enrolled !== null
            && $this->attended !== null
            && $this->completed !== null
            && $this->passed !== null;
    }

    public function passRate(): ?float
    {
        if (! $this->isAvailable() || $this->completed === 0) {
            return null;
        }

        return round(($this->passed / $this->completed) * 100, 1);
    }
}
