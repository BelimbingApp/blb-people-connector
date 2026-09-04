<?php

namespace App\Domains\PeopleConnector\Skill\Workflow;

use App\Base\Workflow\DTO\TransitionContext;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
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
        $this->requireTransaction();

        DB::table('people_connector_skill_requirement_profile_transition_proofs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (int) $profile->tenant_id,
            'profile_id' => (int) $profile->getKey(),
            'subject_id' => null,
            'operation' => 'transition',
            'from_status' => $from->value,
            'to_status' => $to->value,
        ]);
    }

    /**
     * Authorize one exact record emitted by the verified DataShare applier.
     * The database consumes this row on insert; package failure rolls it back
     * with the applier transaction.
     *
     * @param  array<string, mixed>  $values
     */
    public function authorizeDatabaseRestore(string $table, array $values): void
    {
        $this->requireTransaction();

        [$operation, $profileId, $subjectId, $toStatus] = match ($table) {
            'people_connector_skill_requirement_profiles' => [
                'restore_profile',
                (int) ($values['id'] ?? 0),
                (int) ($values['id'] ?? 0),
                (string) ($values['status'] ?? ''),
            ],
            'people_connector_skill_requirement_items' => [
                'restore_item',
                (int) ($values['profile_id'] ?? 0),
                (int) ($values['id'] ?? 0),
                null,
            ],
            'people_connector_skill_requirement_profile_selectors' => [
                'restore_selector',
                (int) ($values['profile_id'] ?? 0),
                (int) ($values['id'] ?? 0),
                null,
            ],
            default => throw new PublishedRequirementImmutableException(
                "Table [{$table}] is not part of requirement-profile restore authority.",
            ),
        };

        $tenantId = (int) ($values['tenant_id'] ?? 0);
        if ($tenantId < 1 || $profileId < 1 || $subjectId < 1) {
            throw new PublishedRequirementImmutableException(
                'Requirement-profile restore authority requires exact tenant, profile, and record identifiers.',
            );
        }

        DB::table('people_connector_skill_requirement_profile_transition_proofs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'profile_id' => $profileId,
            'subject_id' => $subjectId,
            'operation' => $operation,
            'from_status' => null,
            'to_status' => $toStatus,
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new PublishedRequirementImmutableException(
                'Requirement-profile persistence authority requires an active database transaction.',
            );
        }
    }
}
