<?php

namespace App\Domains\PeopleConnector\Connector\Models;

/**
 * A delegated authority the command port has accepted (#185). The row's
 * existence is the single-use check: the unique (tenant, jti) key makes a
 * second presentation a replay. Written through DelegatedAuthorityLedger,
 * never read back by the port; the model is here so the domain's owned-table
 * registry, data share scope and retention policy all see the table.
 */
final class DelegatedAuthoritySpend extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_delegated_spends';

    protected function casts(): array
    {
        return [
            'spent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
