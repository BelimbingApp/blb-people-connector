<?php

return [
    'domains' => [
        'people-connector' => 'Provider-neutral People connections and connector-owned capabilities.',
    ],

    'capabilities' => [
        'people-connector.connection.list',
        'people-connector.connection.manage',
        'people-connector.provider.read',
        'people-connector.provider.write',
        'people-connector.identity.manage',
        'people-connector.support.break-glass',
    ],
];
