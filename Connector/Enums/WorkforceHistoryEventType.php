<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum WorkforceHistoryEventType: string
{
    case IdentityAttached = 'identity_attached';
    case IdentityRemapped = 'identity_remapped';
    case EntityMerged = 'entity_merged';
    case IdentityDeactivated = 'identity_deactivated';
    case IdentityReactivated = 'identity_reactivated';
    case IdentityErased = 'identity_erased';
    case ProjectionUpserted = 'projection_upserted';
}
