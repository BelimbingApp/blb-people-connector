<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Base\Workflow\Contracts\PresentsWorkflowNotifications;
use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;

/**
 * A versioned requirement profile defining what skills a position requires.
 * Once published, the profile and its items are immutable historical policy;
 * edits produce a new version with an effective date. Employee movement does
 * not rewrite past requirements or assessments.
 */
class RequirementProfile extends TenantOwnedModel implements PresentsWorkflowNotifications, ReferencesWorkforceEntities
{
    use CompanyOwned;
    use HasWorkflowStatus;

    public const WORKFLOW_FLOW = 'people_connector_requirement_profile';

    protected $table = 'people_connector_skill_requirement_profiles';

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('owner_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RequirementProfile $profile): void {
            $original = $profile->getOriginal('status');
            $original = $original instanceof RequirementProfileStatus
                ? $original
                : RequirementProfileStatus::from((string) $original);

            $next = $profile->status;
            $next = $next instanceof RequirementProfileStatus
                ? $next
                : RequirementProfileStatus::from((string) $next);

            if ($original === RequirementProfileStatus::Draft && $next === RequirementProfileStatus::Draft) {
                return;
            }

            if ($profile->isLifecycleTransition($original, $next)) {
                if ($next === RequirementProfileStatus::Published && $profile->published_at === null) {
                    $profile->retirePublishedPredecessor();
                    $profile->published_at = now();
                }
                if ($next === RequirementProfileStatus::Retired && $profile->retired_at === null) {
                    $profile->retired_at = now();
                }

                return;
            }

            if ($original === RequirementProfileStatus::Published && $profile->isRetireOnlyChange()) {
                return;
            }

            if ($profile->isCarriedByCompanyMerge()) {
                return;
            }

            throw new PublishedRequirementImmutableException(
                "Requirement profile {$profile->getKey()} is {$original->value} and cannot be modified; draft a new version instead.",
            );
        });

        static::deleting(function (RequirementProfile $profile): void {
            if ($profile->published_at !== null) {
                throw new PublishedRequirementImmutableException(
                    "Requirement profile {$profile->getKey()} has been published and cannot be deleted.",
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => RequirementProfileStatus::class,
            'effective_date' => 'date',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status !== RequirementProfileStatus::Draft;
    }

    public function flow(): string
    {
        return self::WORKFLOW_FLOW;
    }

    public function workflowNotificationTitle(): string
    {
        return "{$this->name} v{$this->version}";
    }

    public function workflowNotificationUrl(): ?string
    {
        return null;
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'requirement_profile', 'id' => $this->getKey()];
    }

    /**
     * True when the pending update only performs the published → retired
     * transition (status + retired_at), leaving every meaning-bearing field
     * untouched.
     */
    private function isRetireOnlyChange(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['status'], $dirty['retired_at'], $dirty['updated_at']);

        return $dirty === [] && $this->status === RequirementProfileStatus::Retired;
    }

    private function isLifecycleTransition(
        RequirementProfileStatus $original,
        RequirementProfileStatus $next,
    ): bool {
        if (! $this->isDirty('status') || ! $original->mayTransitionTo($next)) {
            return false;
        }

        $dirty = $this->getDirty();
        unset($dirty['status'], $dirty['published_at'], $dirty['retired_at'], $dirty['updated_at']);

        return $dirty === [];
    }

    /**
     * Retire the previous current version before this row enters Published.
     * This ordering lets the partial unique index enforce one current version
     * under concurrent publishers without a transient two-published state.
     */
    private function retirePublishedPredecessor(): void
    {
        self::query()
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)
            ->where('code', $this->code)
            ->where('status', RequirementProfileStatus::Published->value)
            ->whereKeyNot($this->getKey())
            ->lockForUpdate()
            ->first()
            ?->update(['status' => RequirementProfileStatus::Retired->value]);
    }

    /**
     * A company merge changes the owner of a non-draft profile and nothing
     * else, and only from an entity already recorded as merged into the new
     * owner. The database trigger applies the same rule; this is the message
     * before the abort.
     */
    private function isCarriedByCompanyMerge(): bool
    {
        $dirty = $this->getDirty();
        unset($dirty['company_entity_id'], $dirty['updated_at']);

        if ($dirty !== [] || $this->isDirty('company_entity_id') === false) {
            return false;
        }

        return WorkforceEntity::query()
            ->forTenant((int) $this->tenant_id)
            ->whereKey((int) $this->getOriginal('company_entity_id'))
            ->where('state', WorkforceEntity::STATE_MERGED)
            ->where('merged_into_entity_id', (int) $this->company_entity_id)
            ->exists();
    }
}
