<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\TransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\Models\StatusTransition;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Database\Eloquent\Model;

final class RequirementProfileTransitionGuard implements TransitionGuard
{
    public function __construct(private readonly SkillAudience $audience) {}

    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult
    {
        if (! $model instanceof RequirementProfile || ! $actor->isUser()) {
            return GuardResult::deny('Requirement-profile transitions require an authenticated user.');
        }

        $user = User::query()->find($actor->id);
        if ($user === null) {
            return GuardResult::deny('The workflow actor no longer exists.');
        }

        $from = RequirementProfileStatus::from($transition->from_code);
        $isHodDecision = $from === RequirementProfileStatus::PendingHodReview;
        $allowed = $isHodDecision
            ? $this->audience->mayReviewRequirementProfile($user, $model)
            : $this->audience->mayGovernRequirements($user, (int) $model->company_entity_id);

        return $allowed
            ? GuardResult::allow()
            : GuardResult::deny('The actor is outside the required company or department review audience.');
    }
}
