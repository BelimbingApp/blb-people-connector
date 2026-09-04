<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Workflow\DTO\TransitionContext;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use WeakMap;

/**
 * One-shot proof that a lifecycle write passed through Base Workflow.
 *
 * Eloquent update hooks cannot otherwise distinguish a guarded WorkflowEngine
 * save from an arbitrary model update that would skip authorization, history,
 * outbox, and transition actions. Proofs are bound to the exact model object
 * locked by WorkflowEngine and are consumed by its next lifecycle save.
 */
final class RequirementProfileTransitionAuthority
{
    /** @var WeakMap<RequirementProfile, array{from: string, to: string, context: ?TransitionContext}> */
    private WeakMap $proofs;

    public function __construct()
    {
        $this->proofs = new WeakMap;
    }

    public function authorize(
        RequirementProfile $profile,
        RequirementProfileStatus $from,
        RequirementProfileStatus $to,
        ?TransitionContext $context = null,
    ): void {
        $this->proofs[$profile] = [
            'from' => $from->value,
            'to' => $to->value,
            'context' => $context,
        ];
    }

    /**
     * @return false|TransitionContext|null False means no matching proof;
     *                                      null is the unit-test fixture proof.
     */
    public function consume(
        RequirementProfile $profile,
        RequirementProfileStatus $from,
        RequirementProfileStatus $to,
    ): false|TransitionContext|null {
        $proof = $this->proofs[$profile] ?? null;
        unset($this->proofs[$profile]);

        if ($proof === null
            || $proof['from'] !== $from->value
            || $proof['to'] !== $to->value) {
            return false;
        }

        return $proof['context'];
    }
}
