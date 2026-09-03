<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

final class WorkforceSnapshot extends TenantOwnedModel
{
    public const UPDATED_AT = null;

    protected $table = 'people_connector_connector_workforce_snapshots';

    protected static function booted(): void
    {
        self::updating(function (self $snapshot): void {
            if ($snapshot->isPrivacyRedaction()) {
                return;
            }

            throw new AppendOnlyRecordException('Workforce snapshots are append-only.');
        });
        self::deleting(fn () => throw new AppendOnlyRecordException('Workforce snapshots are append-only.'));
    }

    /**
     * Clear the provider payload in place. Identity, event key, and provenance
     * metadata stay; only the sensitive body is replaced with a stub.
     */
    public function redact(\DateTimeInterface $redactedAt): void
    {
        if ($this->redacted_at !== null) {
            return;
        }

        $this->forceFill([
            'payload' => [
                'redacted' => true,
                'redacted_at' => $redactedAt instanceof \DateTimeInterface
                    ? $redactedAt->format(\DateTimeInterface::ATOM)
                    : (string) $redactedAt,
            ],
            'redacted_at' => $redactedAt,
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'redacted_at' => 'immutable_datetime',
            'payload' => 'array',
            'provenance' => 'array',
        ];
    }

    private function isPrivacyRedaction(): bool
    {
        if ($this->getOriginal('redacted_at') !== null) {
            return false;
        }

        $dirty = $this->getDirty();
        unset($dirty['payload'], $dirty['redacted_at']);

        return $dirty === [] && $this->isDirty('payload') && $this->isDirty('redacted_at');
    }
}
