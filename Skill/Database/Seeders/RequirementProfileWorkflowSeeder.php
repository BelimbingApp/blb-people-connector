<?php

namespace App\Domains\PeopleConnector\Skill\Database\Seeders;

use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAction;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionGuard;
use Illuminate\Database\Seeder;

final class RequirementProfileWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Workflow::query()->updateOrCreate(
            ['code' => RequirementProfile::WORKFLOW_FLOW],
            [
                'label' => 'Requirement Profile Governance',
                'module' => 'people/skill',
                'description' => 'HOD technical review and HR governance before requirement-profile publication.',
                'model_class' => RequirementProfile::class,
                'is_active' => true,
            ],
        );

        $statuses = [
            ['code' => 'draft', 'label' => 'Draft', 'position' => 0, 'pic' => ['people_hr']],
            ['code' => 'pending_hod_review', 'label' => 'Pending HOD Review', 'position' => 1, 'pic' => ['people_hod']],
            ['code' => 'pending_hr_review', 'label' => 'Pending HR Review', 'position' => 2, 'pic' => ['people_hr']],
            ['code' => 'approved', 'label' => 'Approved for Publication', 'position' => 3, 'pic' => ['people_hr']],
            ['code' => 'published', 'label' => 'Published', 'position' => 4, 'pic' => []],
            ['code' => 'retired', 'label' => 'Retired', 'position' => 5, 'pic' => []],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => RequirementProfile::WORKFLOW_FLOW, 'code' => $status['code']],
                $status + [
                    'flow' => RequirementProfile::WORKFLOW_FLOW,
                    'notifications' => $status['pic'] === []
                        ? null
                        : ['on_enter' => ['pic'], 'channels' => ['database']],
                    'comment_tags' => ['decision', 'returned', 'governance'],
                    'is_active' => true,
                ],
            );
        }

        $edges = [
            ['draft', 'pending_hod_review', 'Submit for HOD review', 'people-connector.skill-requirement.submit'],
            ['pending_hod_review', 'draft', 'Return to draft', 'people-connector.skill-requirement.hod-approve'],
            ['pending_hod_review', 'pending_hr_review', 'Approve technical review', 'people-connector.skill-requirement.hod-approve'],
            ['pending_hr_review', 'draft', 'Return to draft', 'people-connector.skill-requirement.approve'],
            ['pending_hr_review', 'approved', 'Approve governance review', 'people-connector.skill-requirement.approve'],
            ['approved', 'draft', 'Reopen draft', 'people-connector.skill-requirement.approve'],
            ['approved', 'published', 'Publish', 'people-connector.skill-requirement-publication.approve'],
            ['published', 'retired', 'Retire', 'people-connector.skill-requirement-retirement.approve'],
        ];

        foreach ($edges as $position => [$from, $to, $label, $capability]) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => RequirementProfile::WORKFLOW_FLOW, 'from_code' => $from, 'to_code' => $to],
                [
                    'label' => $label,
                    'capability' => $capability,
                    'guard_class' => RequirementProfileTransitionGuard::class,
                    'action_class' => RequirementProfileTransitionAction::class,
                    'position' => $position,
                    'is_active' => true,
                ],
            );
        }
    }
}
