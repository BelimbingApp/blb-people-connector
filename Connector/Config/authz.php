<?php

use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;

// One permission per PeopleCapability case per direction, generated rather
// than hand-listed so a new case can never leave a data class ungated. See
// docs/contracts/hr-data-boundary.md rule 7.3: a role holds access only
// through a permission that names the capability, never through the shared
// 'people-connector.provider.read'/'write' grant this replaced.
$providerPortCapabilities = [];
foreach (PeopleCapability::cases() as $capability) {
    $providerPortCapabilities[] = ProviderPortAuthorization::permissionFor($capability, 'read');
    $providerPortCapabilities[] = ProviderPortAuthorization::permissionFor($capability, 'write');
}

return [
    'domains' => [
        'people-connector' => 'Provider-neutral People connections and connector-owned capabilities.',
    ],

    'capabilities' => [
        'people-connector.connection.list',
        'people-connector.connection.manage',
        'people-connector.identity.manage',
        'people-connector.retention.review',
        'people-connector.support.break-glass',
        ...$providerPortCapabilities,
    ],
];
