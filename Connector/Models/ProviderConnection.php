<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

final class ProviderConnection extends TenantOwnedModel
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'people_connector_connector_provider_connections';

    protected static function booted(): void
    {
        self::saving(function (ProviderConnection $connection): void {
            $expectedScopeKey = $connection->company_id === null
                ? 'tenant'
                : 'company:'.(int) $connection->company_id;

            if ($connection->scope_key !== $expectedScopeKey) {
                throw new InvalidProviderConfigurationException('Provider connection scope fields are inconsistent.');
            }

            if (! in_array($connection->status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
                throw new InvalidProviderConfigurationException('Provider connection status is invalid.');
            }

            $connection->active_scope_key = $connection->status === self::STATUS_ACTIVE
                ? $expectedScopeKey
                : null;

            if ($connection->exists && ($connection->isDirty('tenant_id') || $connection->isDirty('scope_key') || $connection->isDirty('company_id') || $connection->isDirty('provider_id'))) {
                throw new InvalidProviderConfigurationException('Provider connection identity and scope are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'public_metadata' => 'array',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }
}
