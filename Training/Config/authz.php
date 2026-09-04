<?php

return [
    'domains' => [
        'people-connector-training' => 'Connector-owned training catalog, schedules, and event register.',
    ],

    'capabilities' => [
        'people-connector.training.event.view',
        'people-connector.training.event.manage',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people-connector.training.event.view',
                'people-connector.training.event.manage',
            ],
        ],
        'people_hod' => [
            'capabilities' => [
                'people-connector.training.event.view',
            ],
        ],
    ],
];
