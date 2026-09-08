<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Enums\ProviderConnectionMode;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

final class ProviderConnection extends TenantOwnedModel
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Finished, not merely switched off.
     *
     * Inactive is reversible: a connection can be activated again, and one is
     * switched off automatically whenever a replacement takes its scope.
     * Retired is a decision that the provider is done — its checkpoints and
     * projections become history, and coming back means configuring a new
     * connection rather than reviving this one.
     */
    public const STATUS_RETIRED = 'retired';

    protected $table = 'people_connector_connector_provider_connections';

    /**
     * Whether a planned maintenance window (#264) currently holds this
     * connection: sync passes skip it and webhook-triggered runs are deferred.
     *
     * Only a future `maintenance_until` counts. A window that has lapsed is
     * cleared here, on the read that notices it, so no purge or scheduler has
     * to own the clean-up and no later reader sees a stale window.
     */
    public function inMaintenance(?\DateTimeImmutable $at = null): bool
    {
        $until = $this->maintenance_until;

        if ($until === null) {
            return false;
        }

        $at ??= \DateTimeImmutable::createFromInterface(now());

        if ($until > $at) {
            return true;
        }

        if ($this->exists) {
            self::query()->whereKey($this->getKey())->update(['maintenance_until' => null, 'maintenance_reason' => null]);
        }
        $this->forceFill(['maintenance_until' => null, 'maintenance_reason' => null]);
        $this->syncOriginalAttributes('maintenance_until', 'maintenance_reason');

        return false;
    }

    protected static function booted(): void
    {
        self::saving(function (ProviderConnection $connection): void {
            $expectedScopeKey = $connection->company_id === null
                ? 'tenant'
                : 'company:'.(int) $connection->company_id;

            if ($connection->scope_key !== $expectedScopeKey) {
                throw new InvalidProviderConfigurationException('Provider connection scope fields are inconsistent.');
            }

            if (! in_array($connection->status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_RETIRED], true)) {
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
            'mode' => ProviderConnectionMode::class,
            'remote_credential_id' => 'integer',
            'public_metadata' => 'array',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'maintenance_until' => 'immutable_datetime',
        ];
    }
}
