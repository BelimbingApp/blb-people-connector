<?php

namespace App\Domains\PeopleConnector\Connector\Models;

final class ExternalIdentity extends TenantOwnedModel
{
    public const STATE_ACTIVE = 'active';

    public const STATE_INACTIVE = 'inactive';

    public const STATE_REMAPPED = 'remapped';

    public const STATE_MERGED = 'merged';

    protected $table = 'people_connector_connector_external_identities';

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'workforce_entity_id' => 'integer',
            'replaced_by_identity_id' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'provenance' => 'array',
        ];
    }
}
