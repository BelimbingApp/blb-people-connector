<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\ContextualTransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Database\Eloquent\Model;

final class RequirementProfileTransitionGuard implements ContextualTransitionGuard
{
    public function __construct(
        private readonly SkillAudience $audience,
        private readonly RequirementProfileTransitionAuthority $authority,
    ) {}

    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult
    {
        return $this->evaluateDecision($model, $transition, $actor);
    }

    public function evaluateWithContext(
        Model $model,
        StatusTransition $transition,
        Actor $actor,
        TransitionContext $context,
    ): GuardResult {
        return $this->evaluateDecision($model, $transition, $actor, $context);
    }

    private function evaluateDecision(
        Model $model,
        StatusTransition $transition,
        Actor $actor,
        ?TransitionContext $context = null,
    ): GuardResult {
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

        if (! $allowed) {
            return GuardResult::deny('The actor is outside the required company or department review audience.');
        }

        $to = RequirementProfileStatus::from($transition->to_code);
        if ($this->requiresDecisionComment($from, $to)
            && trim((string) $context?->comment) === '') {
            return GuardResult::deny('A review comment or reason is required.');
        }

        $this->authority->authorize($model, $from, $to);

        return GuardResult::allow();
    }

    private function requiresDecisionComment(
        RequirementProfileStatus $from,
        RequirementProfileStatus $to,
    ): bool {
        return ! ($from === RequirementProfileStatus::Draft && $to === RequirementProfileStatus::PendingHodReview)
            && ! ($from === RequirementProfileStatus::Approved && $to === RequirementProfileStatus::Published);
    }
}
