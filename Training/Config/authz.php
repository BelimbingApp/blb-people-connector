<?php

return [
    'domains' => [
        'people-connector-training' => 'Connector-owned training catalog, schedules, and event register.',
    ],

    'capabilities' => [
        'people-connector.training.event.view',
        'people-connector.training.event.manage',
        'people-connector.training.request.submit',
        'people-connector.training.request.hod-review',
        'people-connector.training.request.hr-review',
        'people-connector.training.request.approve',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people-connector.training.event.view',
                'people-connector.training.event.manage',
                'people-connector.training.request.submit',
                'people-connector.training.request.hr-review',
                'people-connector.training.request.approve',
            ],
        ],
        'people_hod' => [
            'capabilities' => [
                'people-connector.training.event.view',
                'people-connector.training.request.submit',
                'people-connector.training.request.hod-review',
            ],
        ],
    ],
];
