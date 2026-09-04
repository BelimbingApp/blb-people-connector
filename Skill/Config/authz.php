<?php

return [
    'domains' => [
        'people-connector-skill' => 'Connector-owned skill catalog, assessments, development actions, and proficiency scales.',
    ],

    'capabilities' => [
        'people-connector.skill.catalog.view',
        'people-connector.skill.catalog.manage',
        'people-connector.skill.assessment.view',
        'people-connector.skill.assessment.manage',
        'people-connector.skill.development-action.view',
        'people-connector.skill.development-action.manage',
        'people-connector.skill.hr.view',
        'people-connector.skill.hod.view',
        'people-connector.skill.assessor.view',
        'people-connector.skill.employee.view',
    ],

    // Audience capabilities identify why a principal may see connector-owned
    // competence data. SkillAudience still resolves the employee boundary;
    // these grants alone never authorize a row. In particular, grant_all
    // platform roles are rejected unless one of these roles is also assigned.
    'roles' => [
        'people_hr' => [
            'name' => 'People HR',
            'description' => 'Governs connector-owned skill catalogues and assessments for an attributed company.',
            'capabilities' => [
                'people-connector.skill.catalog.view',
                'people-connector.skill.catalog.manage',
                'people-connector.skill.assessment.view',
                'people-connector.skill.assessment.manage',
                'people-connector.skill.development-action.view',
                'people-connector.skill.development-action.manage',
                'people-connector.skill.hr.view',
            ],
        ],
        'people_hod' => [
            'name' => 'People HOD / Manager',
            'description' => 'Views and assesses only employees in the holder’s projected department or reporting team.',
            'capabilities' => [
                'people-connector.skill.catalog.view',
                'people-connector.skill.assessment.view',
                'people-connector.skill.assessment.manage',
                'people-connector.skill.development-action.view',
                'people-connector.skill.development-action.manage',
                'people-connector.skill.hod.view',
            ],
        ],
        'people_assessor' => [
            'name' => 'People Assessor',
            'description' => 'Views the skill catalogue and assesses only explicitly assigned employees.',
            'capabilities' => [
                'people-connector.skill.catalog.view',
                'people-connector.skill.assessment.view',
                'people-connector.skill.assessment.manage',
                'people-connector.skill.assessor.view',
            ],
        ],
        'people_employee' => [
            'name' => 'People Employee',
            'description' => 'Views the skill catalogue and only the holder’s own connector-owned assessment record.',
            'capabilities' => [
                'people-connector.skill.catalog.view',
                'people-connector.skill.assessment.view',
                'people-connector.skill.employee.view',
            ],
        ],
    ],
];
