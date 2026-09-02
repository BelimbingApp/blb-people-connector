<?php

return [
    'items' => [
        [
            'id' => 'admin.system.people-connector',
            'label' => 'People Connections',
            'icon' => 'heroicon-o-arrows-right-left',
            'route' => 'admin.people-connector.connections.index',
            'permission' => 'people-connector.connection.list',
            'parent' => 'admin.system.integrations',
        ],
    ],
];
