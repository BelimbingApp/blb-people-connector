<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\RefusesDeletion;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;
use DateTimeImmutable;

/**
 * Break-glass authorisation evidence. Append-only except a one-way revoke:
 * `revoked_at` may move from null to a timestamp once; every other mutation
 * and every delete is refused so the retention config's indefinite claim is true.
 */
final class PrivilegedSupportGrant extends TenantOwnedModel implements RefusesDeletion
{
    protected $table = 'people_connector_connector_privileged_support_grants';

    protected static function booted(): void
    {
        self::updating(function (self $grant): void {
            if ($grant->isRevocation()) {
                return;
            }

            throw new AppendOnlyRecordException('Privileged support grants are append-only.');
        });
        self::deleting(function (): void {
            throw new AppendOnlyRecordException('Privileged support grants cannot be deleted.');
        });
    }

    public function isActive(DateTimeImmutable $at): bool
    {
        return $this->revoked_at === null && $at >= $this->issued_at && $at < $this->expires_at;
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'capabilities' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Revoke is the sole in-place mutation: first write of `revoked_at` only.
     * Matches WorkforceSnapshot's privacy-redaction carve-out shape.
     */
    private function isRevocation(): bool
    {
        if ($this->getOriginal('revoked_at') !== null) {
            return false;
        }

        $dirty = $this->getDirty();
        unset($dirty['revoked_at'], $dirty['updated_at']);

        return $dirty === []
            && $this->isDirty('revoked_at')
            && $this->revoked_at !== null;
    }
}
