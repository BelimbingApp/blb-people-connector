<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Workflow\DTO\TransitionContext;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /**
     * Materialize the already-consumed in-process proof for the database
     * trigger. The row is transaction-local in effect: the trigger consumes
     * it on the exact next edge, and a rollback removes it with the update.
     */
    public function authorizeDatabaseWrite(
        RequirementProfile $profile,
        RequirementProfileStatus $from,
        RequirementProfileStatus $to,
    ): void {
        DB::table('people_connector_skill_requirement_profile_transition_proofs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (int) $profile->tenant_id,
            'profile_id' => (int) $profile->getKey(),
            'from_status' => $from->value,
            'to_status' => $to->value,
        ]);
    }
}
