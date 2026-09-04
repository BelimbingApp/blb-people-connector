<?php

return [
    'items' => [[
        'id' => 'people.training-events',
        'label' => 'Training schedule',
        'icon' => 'heroicon-o-calendar-days',
        'route' => 'people-connector.training.events.index',
        'permission' => 'people-connector.training.event.view',
        'condition' => 'people-connector.training.event-audience',
        'parent' => 'people',
    ]],
];
