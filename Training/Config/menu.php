<?php

return [
    'items' => [[
        'id' => 'people.training-catalog',
        'label' => 'Training catalog',
        'icon' => 'heroicon-o-academic-cap',
        'route' => 'people-connector.training.catalog.index',
        'permission' => 'people-connector.training.event.view',
        'condition' => 'people-connector.training.event-audience',
        'parent' => 'people',
    ], [
        'id' => 'people.training-events',
        'label' => 'Training schedule',
        'icon' => 'heroicon-o-calendar-days',
        'route' => 'people-connector.training.events.index',
        'permission' => 'people-connector.training.event.view',
        'condition' => 'people-connector.training.event-audience',
        'parent' => 'people',
    ]],
];
