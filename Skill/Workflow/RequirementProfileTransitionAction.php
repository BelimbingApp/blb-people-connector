<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfilePublished;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfileRetired;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RequirementProfileTransitionAction implements TransitionAction
{
    public function execute(Model $model, StatusTransition $transition, TransitionContext $context): void
    {
        if (! $model instanceof RequirementProfile) {
            return;
        }

        $to = RequirementProfileStatus::from($transition->to_code);
        if ($to === RequirementProfileStatus::Published) {
            DB::afterCommit(fn () => event(new RequirementProfilePublished(
                (int) $model->tenant_id,
                (int) $model->getKey(),
                (string) $model->code,
                (int) $model->version,
                $model->publicationPredecessorId(),
            )));
        }

        if ($to === RequirementProfileStatus::Retired) {
            DB::afterCommit(fn () => event(new RequirementProfileRetired(
                (int) $model->tenant_id,
                (int) $model->getKey(),
                (string) $model->code,
                (int) $model->version,
            )));
        }
    }
}
