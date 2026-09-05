<?php

namespace App\Domains\PeopleConnector\Skill\Listeners;

use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Notifications\TransitionNotification;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;

/**
 * Delivers connector-resolved PIC notifications from the durable Workflow
 * outbox. Deterministic notification ids make at-least-once replay safe.
 */
final class SendRequirementProfileTransitionNotification
{
    public function handle(TransitionCompleted $event): void
    {
        if ($event->flow !== RequirementProfile::WORKFLOW_FLOW || $event->context->assignees === null) {
            return;
        }

        $userIds = collect($event->context->assignees)
            ->pluck('user_id')
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(intval(...))
            ->unique()
            ->reject(fn (int $id): bool => $event->context->actor->isUser()
                && $id === $event->context->actor->id)
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $notification = new TransitionNotification(
            flow: $event->flow,
            model: $event->model,
            transition: $event->transition,
            history: $event->history,
        );

        User::query()->whereKey($userIds)->each(
            fn (User $user) => $user->notify($notification->forNotifiable($user)),
        );
    }
}
