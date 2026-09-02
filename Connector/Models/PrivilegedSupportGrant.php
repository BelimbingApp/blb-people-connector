<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use DateTimeImmutable;

final class PrivilegedSupportGrant extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_privileged_support_grants';

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
}
