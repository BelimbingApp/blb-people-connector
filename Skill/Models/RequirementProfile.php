<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Base\Workflow\Contracts\PresentsWorkflowNotifications;
use App\Base\Workflow\DTO\TransitionContext;
use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;

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

    private ?int $publicationPredecessorId = null;

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('owner_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RequirementProfile $profile): void {
            if ($profile->status !== RequirementProfileStatus::Draft
                || $profile->published_at !== null
                || $profile->retired_at !== null) {
                throw new PublishedRequirementImmutableException(
                    'Requirement profiles must enter the governed lifecycle as drafts.',
                );
            }
        });

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
                $authority = app(RequirementProfileTransitionAuthority::class);
                $context = $authority->consume($profile, $original, $next);
                if ($context === false) {
                    throw new PublishedRequirementImmutableException(
                        "Requirement profile {$profile->getKey()} lifecycle changes must use the governed workflow.",
                    );
                }
                $authority->authorizeDatabaseWrite($profile, $original, $next);

                if ($next === RequirementProfileStatus::Published) {
                    $profile->publicationPredecessorId = $context instanceof TransitionContext
                        ? $profile->retirePublishedPredecessorThroughWorkflow($context)
                        : $profile->retirePublishedPredecessorFixture();
                    $profile->published_at ??= now();
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
            if ($profile->status !== RequirementProfileStatus::Draft || $profile->published_at !== null) {
                throw new PublishedRequirementImmutableException(
                    "Requirement profile {$profile->getKey()} entered governance and cannot be deleted.",
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
        return route('people-connector.skill.requirement-profiles.show', [
            'profileId' => $this->getKey(),
        ]);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'requirement_profile', 'id' => $this->getKey()];
    }

    public function publicationPredecessorId(): ?int
    {
        return $this->publicationPredecessorId;
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
    private function publishedPredecessor(): ?RequirementProfile
    {
        return self::query()
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)
            ->where('code', $this->code)
            ->where('status', RequirementProfileStatus::Published->value)
            ->whereKeyNot($this->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Run automatic predecessor retirement as its own governed transition so
     * history, audit, outbox, notifications, and the domain event all describe
     * the same lifecycle change. The nested savepoint remains inside the
     * publisher's outer transaction, so both versions commit or roll back.
     */
    private function retirePublishedPredecessorThroughWorkflow(TransitionContext $publishingContext): ?int
    {
        $previous = $this->publishedPredecessor();

        if ($previous === null) {
            return null;
        }

        $participant = RequirementProfileWorkflowParticipant::query()
            ->forCompany((int) $previous->tenant_id, (int) $previous->company_entity_id)
            ->findOrFail($previous->getKey());
        $result = $participant->transitionTo(RequirementProfileStatus::Retired->value, new TransitionContext(
            actor: $publishingContext->actor,
            comment: "Automatically retired when {$this->code} v{$this->version} was published.",
            commentTag: 'superseded',
            metadata: [
                'tenant_id' => (int) $this->tenant_id,
                'company_entity_id' => (int) $this->company_entity_id,
                'profile_code' => (string) $previous->code,
                'profile_version' => (int) $previous->version,
                'replacement_profile_id' => (int) $this->getKey(),
                'replacement_profile_version' => (int) $this->version,
                'capability' => 'people-connector.skill-requirement-retirement.approve',
            ],
        ));

        if (! $result->success) {
            throw new PublishedRequirementImmutableException(
                $result->reason ?? "Requirement profile {$previous->getKey()} could not be retired through workflow.",
            );
        }

        return (int) $previous->getKey();
    }

    /** Unit-test persistence fixtures do not install actors or workflow rows. */
    private function retirePublishedPredecessorFixture(): ?int
    {
        $previous = $this->publishedPredecessor();
        if ($previous === null) {
            return null;
        }

        app(RequirementProfileTransitionAuthority::class)->authorize(
            $previous,
            RequirementProfileStatus::Published,
            RequirementProfileStatus::Retired,
        );
        $previous->update(['status' => RequirementProfileStatus::Retired->value]);

        return (int) $previous->getKey();
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
