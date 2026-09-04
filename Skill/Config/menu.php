<?php

return [
    'items' => [
        [
            // Renders under the People bucket when the People domain provides
            // it; with no People anchor installed the item stays hidden until
            // a connector-owned anchor lands with the adapter work.
            'id' => 'people.skills',
            'label' => 'Skills',
            'icon' => 'heroicon-o-academic-cap',
            'route' => 'people-connector.skill.catalog.index',
            'permission' => 'people-connector.skill.catalog.view',
            'condition' => 'people-connector.skill.catalog-audience',
            'parent' => 'people',
        ],
        [
            'id' => 'people.skill-assessments',
            'label' => 'Skill assessments',
            'icon' => 'heroicon-o-clipboard-document-check',
            'route' => 'people-connector.skill.assessment.matrix',
            'permission' => 'people-connector.skill.assessment.view',
            'condition' => 'people-connector.skill.assessment-audience',
            'parent' => 'people',
        ],
    ],
];
