<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Workflow\DTO\TransitionContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft;
use App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfilePublished;
use App\Domains\PeopleConnector\Skill\Events\RequirementProfileRetired;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidRequirementProfileException;
use App\Domains\PeopleConnector\Skill\Exceptions\RequirementProfileNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileWorkflowParticipant;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Versioned requirement profile lifecycle: draft → HOD review → HR review →
 * approved → published → retired, with reviewed return-to-draft edges.
 * Publishing retires the previously published version of the same code, so
 * exactly one version of a profile code is current per company. Published
 * profiles are immutable historical policy; employee movement does not rewrite
 * past requirements or assessments.
 */
class RequirementProfileStore
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,79}$/';

    private const WEIGHT_TOLERANCE = 0.0001;

    public function draft(int $companyEntityId, RequirementProfileDraft $draft): RequirementProfile
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        if (preg_match(self::CODE_PATTERN, $draft->code) !== 1) {
            throw new InvalidRequirementProfileException(
                'A profile code must be 1-80 lowercase letters, digits, dots, dashes, or underscores, starting with a letter or digit.',
            );
        }

        $this->assertCompanyEntity($tenantId, $companyEntityId);
        $this->assertSelectors($tenantId, $companyEntityId, $draft->selectors);
        $this->assertItems($tenantId, $companyEntityId, $draft->items);

        if ($draft->ownerEmployeeEntityId !== null) {
            $this->assertEmployeeEntity($tenantId, $draft->ownerEmployeeEntityId);
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $draft): RequirementProfile {
            $currentMax = (int) RequirementProfile::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('code', $draft->code)
                ->max('version');

            if ($this->draftOf($tenantId, $companyEntityId, $draft->code) !== null) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$draft->code}] already has an open draft; publish or discard it before drafting again.",
                );
            }

            $profile = RequirementProfile::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'code' => $draft->code,
                'name' => $draft->name,
                'version' => $currentMax + 1,
                'status' => RequirementProfileStatus::Draft,
                'effective_date' => $draft->effectiveDate,
                'owner_employee_entity_id' => $draft->ownerEmployeeEntityId,
            ]);

            $this->writeSelectors($profile, $draft->selectors);
            $this->writeItems($profile, $draft->items);

            return $profile;
        });
    }

    public function newDraftFrom(int $companyEntityId, int $profileId): RequirementProfile
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $source = $this->requireProfile($tenantId, $companyEntityId, $profileId);

        $selectors = RequirementProfileSelector::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('profile_id', $source->getKey())
            ->get()
            ->map(fn (RequirementProfileSelector $selector): RequirementSelectorDraft => new RequirementSelectorDraft(
                $selector->selector_type,
                $selector->selector_value,
                $selector->selector_entity_id,
            ))
            ->all();

        $items = RequirementItem::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('profile_id', $source->getKey())
            ->get()
            ->map(fn (RequirementItem $item): RequirementItemDraft => new RequirementItemDraft(
                (int) $item->skill_id,
                (int) $item->sequence,
                (int) $item->required_level,
                $item->criticality,
                (float) $item->weight_percent,
                $item->evidence_standard,
                (bool) $item->mandatory_gate,
                $item->reassessment_months,
                (bool) $item->active,
            ))
            ->all();

        return $this->draft($companyEntityId, new RequirementProfileDraft(
            (string) $source->code,
            (string) $source->name,
            $selectors,
            $items,
            $source->effective_date,
            $source->owner_employee_entity_id,
        ));
    }

    public function publish(int $companyEntityId, int $profileId): RequirementProfile
    {
        if (! app()->runningUnitTests()) {
            throw new InvalidRequirementProfileException(
                'Direct publication is disabled; use the governed HOD and HR review workflow.',
            );
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        return DB::transaction(function () use ($tenantId, $companyEntityId, $profileId): RequirementProfile {
            $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

            // Existing catalog tests exercise persistence and resolution rather
            // than actor policy. Keep their fixture helper on the real database
            // transition graph, without exposing this shortcut in a running app.
            if (app()->runningUnitTests() && $profile->status === RequirementProfileStatus::Draft) {
                foreach ([
                    RequirementProfileStatus::PendingHodReview,
                    RequirementProfileStatus::PendingHrReview,
                    RequirementProfileStatus::Approved,
                ] as $fixtureStatus) {
                    app(RequirementProfileTransitionAuthority::class)->authorize(
                        $profile,
                        $profile->status,
                        $fixtureStatus,
                    );
                    $profile->update(['status' => $fixtureStatus]);
                }
            }

            if ($profile->status !== RequirementProfileStatus::Approved) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only an HR-approved profile can be published.",
                );
            }

            $items = RequirementItem::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('profile_id', $profile->getKey())
                ->get();
            $this->assertPublishableItems($items->all());

            $newSelectors = RequirementProfileSelector::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('profile_id', $profile->getKey())
                ->get();
            $this->assertNoOverlap($tenantId, $companyEntityId, $profile, $newSelectors);

            app(RequirementProfileTransitionAuthority::class)->authorize(
                $profile,
                RequirementProfileStatus::Approved,
                RequirementProfileStatus::Published,
            );
            $profile->update([
                'status' => RequirementProfileStatus::Published,
                'published_at' => now(),
            ]);

            event(new RequirementProfilePublished(
                $tenantId,
                (int) $profile->getKey(),
                (string) $profile->code,
                (int) $profile->version,
                $profile->publicationPredecessorId(),
            ));

            return $profile;
        });
    }

    public function retire(int $companyEntityId, int $profileId): RequirementProfile
    {
        if (! app()->runningUnitTests()) {
            throw new InvalidRequirementProfileException(
                'Direct retirement is disabled; use the governed requirement-profile workflow.',
            );
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

        if ($profile->status !== RequirementProfileStatus::Published) {
            throw new InvalidRequirementProfileException(
                "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only a published profile can be retired.",
            );
        }

        app(RequirementProfileTransitionAuthority::class)->authorize(
            $profile,
            RequirementProfileStatus::Published,
            RequirementProfileStatus::Retired,
        );
        $profile->update([
            'status' => RequirementProfileStatus::Retired,
            'retired_at' => now(),
        ]);

        event(new RequirementProfileRetired(
            $tenantId,
            (int) $profile->getKey(),
            (string) $profile->code,
            (int) $profile->version,
        ));

        return $profile;
    }

    public function discardDraft(int $companyEntityId, int $profileId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        DB::transaction(function () use ($tenantId, $companyEntityId, $profileId): void {
            $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

            if ($profile->status !== RequirementProfileStatus::Draft) {
                throw new InvalidRequirementProfileException(
                    "Profile [{$profile->code}] v{$profile->version} is {$profile->status->value}; only a draft can be discarded.",
                );
            }

            $profile->delete();
        });
    }

    public function currentProfile(int $companyEntityId, string $code): ?RequirementProfile
    {
        return $this->publishedOf(app(TenantContext::class)->requireTenantId(), $companyEntityId, $code);
    }

    public function submitForReview(
        User $actor,
        int $companyEntityId,
        int $profileId,
        ?string $comment = null,
    ): RequirementProfile {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $profile = $this->requireProfile($tenantId, $companyEntityId, $profileId);

        return $this->transition($actor, $profile, RequirementProfileStatus::PendingHodReview, $comment);
    }

    /**
     * Validate and freeze the exact child set while WorkflowEngine holds the
     * profile row lock. PostgreSQL child guards acquire a parent lock too, so
     * concurrent inserts serialize on the same boundary instead of slipping
     * between validation and the draft-to-review transition.
     */
    public function validateSubmission(RequirementProfile $profile): void
    {
        $tenantId = (int) $profile->tenant_id;
        $companyEntityId = (int) $profile->company_entity_id;
        $items = RequirementItem::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('profile_id', $profile->getKey())
            ->lockForUpdate()
            ->get();
        $selectors = RequirementProfileSelector::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('profile_id', $profile->getKey())
            ->lockForUpdate()
            ->get();

        $this->assertPublishableItems($items->all());
        $this->assertNoOverlap($tenantId, $companyEntityId, $profile, $selectors);
    }

    public function approveHod(User $actor, int $companyEntityId, int $profileId, string $comment): RequirementProfile
    {
        return $this->transitionRequiredComment(
            $actor, $companyEntityId, $profileId, RequirementProfileStatus::PendingHrReview, $comment,
        );
    }

    public function returnByHod(User $actor, int $companyEntityId, int $profileId, string $reason): RequirementProfile
    {
        return $this->transitionRequiredComment(
            $actor, $companyEntityId, $profileId, RequirementProfileStatus::Draft, $reason, 'returned',
        );
    }

    public function approveHr(User $actor, int $companyEntityId, int $profileId, string $comment): RequirementProfile
    {
        return $this->transitionRequiredComment(
            $actor, $companyEntityId, $profileId, RequirementProfileStatus::Approved, $comment,
        );
    }

    public function returnByHr(User $actor, int $companyEntityId, int $profileId, string $reason): RequirementProfile
    {
        return $this->transitionRequiredComment(
            $actor, $companyEntityId, $profileId, RequirementProfileStatus::Draft, $reason, 'returned',
        );
    }

    public function publishApproved(User $actor, int $companyEntityId, int $profileId): RequirementProfile
    {
        return $this->transition(
            $actor,
            $this->requireProfile(app(TenantContext::class)->requireTenantId(), $companyEntityId, $profileId),
            RequirementProfileStatus::Published,
        );
    }

    public function retireGoverned(User $actor, int $companyEntityId, int $profileId, string $reason): RequirementProfile
    {
        return $this->transitionRequiredComment(
            $actor, $companyEntityId, $profileId, RequirementProfileStatus::Retired, $reason,
        );
    }

    /**
     * Queue membership is derived from the committed workflow state and the
     * same deep audience used by the transition guard; it is never copied to
     * a mutable assignment column.
     *
     * @return Collection<int, RequirementProfile>
     */
    public function reviewQueue(User $actor, int $companyEntityId): Collection
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $profiles = RequirementProfile::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('status', [
                RequirementProfileStatus::PendingHodReview->value,
                RequirementProfileStatus::PendingHrReview->value,
                RequirementProfileStatus::Approved->value,
            ])->orderBy('code')->orderBy('version')->get();

        return $profiles->filter(function (RequirementProfile $profile) use ($actor): bool {
            if ($profile->status === RequirementProfileStatus::PendingHodReview) {
                return app(SkillAudience::class)->mayReviewRequirementProfile($actor, $profile);
            }

            return app(SkillAudience::class)->mayGovernRequirements($actor, (int) $profile->company_entity_id);
        })->values();
    }

    private function transitionRequiredComment(
        User $actor,
        int $companyEntityId,
        int $profileId,
        RequirementProfileStatus $to,
        string $comment,
        string $commentTag = 'decision',
    ): RequirementProfile {
        if (trim($comment) === '') {
            throw new InvalidRequirementProfileException('A review comment or reason is required.');
        }

        return $this->transition(
            $actor,
            $this->requireProfile(app(TenantContext::class)->requireTenantId(), $companyEntityId, $profileId),
            $to,
            trim($comment),
            $commentTag,
        );
    }

    private function transition(
        User $actor,
        RequirementProfile $profile,
        RequirementProfileStatus $to,
        ?string $comment = null,
        string $commentTag = 'governance',
    ): RequirementProfile {
        $participant = RequirementProfileWorkflowParticipant::query()
            ->forCompany((int) $profile->tenant_id, (int) $profile->company_entity_id)
            ->findOrFail($profile->getKey());
        $result = $participant->transitionTo($to->value, new TransitionContext(
            actor: Actor::forUser($actor),
            comment: $comment,
            commentTag: $commentTag,
            assignees: array_map(
                static fn (int $userId): array => ['user_id' => $userId],
                app(SkillAudience::class)->requirementReviewerUserIds($profile, $to),
            ),
            metadata: [
                'tenant_id' => (int) $profile->tenant_id,
                'company_entity_id' => (int) $profile->company_entity_id,
                'profile_code' => (string) $profile->code,
                'profile_version' => (int) $profile->version,
                'capability' => $this->transitionCapability($profile->status, $to),
            ],
        ));

        if (! $result->success) {
            throw new InvalidRequirementProfileException($result->reason ?? 'Requirement-profile transition failed.');
        }

        return $profile->refresh();
    }

    private function transitionCapability(
        RequirementProfileStatus $from,
        RequirementProfileStatus $to,
    ): string {
        return match ([$from, $to]) {
            [RequirementProfileStatus::Draft, RequirementProfileStatus::PendingHodReview] => 'people-connector.skill-requirement.submit',
            [RequirementProfileStatus::PendingHodReview, RequirementProfileStatus::Draft],
            [RequirementProfileStatus::PendingHodReview, RequirementProfileStatus::PendingHrReview] => 'people-connector.skill-requirement.hod-approve',
            [RequirementProfileStatus::PendingHrReview, RequirementProfileStatus::Draft],
            [RequirementProfileStatus::PendingHrReview, RequirementProfileStatus::Approved],
            [RequirementProfileStatus::Approved, RequirementProfileStatus::Draft] => 'people-connector.skill-requirement.approve',
            [RequirementProfileStatus::Approved, RequirementProfileStatus::Published] => 'people-connector.skill-requirement-publication.approve',
            [RequirementProfileStatus::Published, RequirementProfileStatus::Retired] => 'people-connector.skill-requirement-retirement.approve',
            [RequirementProfileStatus::PendingHodReview, RequirementProfileStatus::Published],
            [RequirementProfileStatus::PendingHrReview, RequirementProfileStatus::Published] => 'people-connector.skill-requirement-publication.approve',
            default => throw new InvalidRequirementProfileException('The requirement-profile transition has no capability contract.'),
        };
    }

    private function requireProfile(int $tenantId, int $companyEntityId, int $profileId): RequirementProfile
    {
        return RequirementProfile::query()->forCompany($tenantId, $companyEntityId)->find($profileId)
            ?? throw new RequirementProfileNotFoundException("Requirement profile [$profileId] was not found.");
    }

    private function draftOf(int $tenantId, int $companyEntityId, string $code): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('code', $code)
            ->where('status', RequirementProfileStatus::Draft->value)
            ->first();
    }

    private function publishedOf(int $tenantId, int $companyEntityId, string $code): ?RequirementProfile
    {
        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('code', $code)
            ->where('status', RequirementProfileStatus::Published->value)
            ->first();
    }

    /**
     * @param  list<RequirementSelectorDraft>  $selectors
     */
    private function writeSelectors(RequirementProfile $profile, array $selectors): void
    {
        foreach ($selectors as $selector) {
            RequirementProfileSelector::query()->create([
                'tenant_id' => $profile->tenant_id,
                'company_entity_id' => $profile->company_entity_id,
                'profile_id' => $profile->getKey(),
                'selector_type' => $selector->selectorType,
                'selector_value' => $selector->selectorValue,
                'selector_entity_id' => $selector->selectorEntityId,
            ]);
        }
    }

    /**
     * @param  list<RequirementItemDraft>  $items
     */
    private function writeItems(RequirementProfile $profile, array $items): void
    {
        foreach ($items as $item) {
            RequirementItem::query()->create([
                'tenant_id' => $profile->tenant_id,
                'company_entity_id' => $profile->company_entity_id,
                'profile_id' => $profile->getKey(),
                'skill_id' => $item->skillId,
                'sequence' => $item->sequence,
                'required_level' => $item->requiredLevel,
                'criticality' => $item->criticality,
                'weight_percent' => $item->weightPercent,
                'evidence_standard' => $item->evidenceStandard,
                'mandatory_gate' => $item->mandatoryGate,
                'reassessment_months' => $item->reassessmentMonths,
                'active' => $item->active,
            ]);
        }
    }

    /**
     * @param  list<RequirementSelectorDraft>  $selectors
     */
    private function assertSelectors(int $tenantId, int $companyEntityId, array $selectors): void
    {
        if (count($selectors) === 0) {
            throw new InvalidRequirementProfileException('A requirement profile needs at least one target selector.');
        }

        foreach ($selectors as $selector) {
            if ($selector->selectorType === SelectorType::Company) {
                if ($selector->selectorEntityId !== null) {
                    throw new InvalidRequirementProfileException('Company selector must not carry a selector_entity_id.');
                }

                continue;
            }

            if ($selector->selectorEntityId === null) {
                throw new InvalidRequirementProfileException(
                    "{$selector->selectorType->value} selector requires a selector_entity_id.",
                );
            }

            $entity = WorkforceEntity::query()->forTenant($tenantId)->find($selector->selectorEntityId);
            if ($entity === null) {
                throw new InvalidRequirementProfileException(
                    "Selector entity [{$selector->selectorEntityId}] was not found.",
                );
            }

            if ($selector->selectorType === SelectorType::Department) {
                if ($entity->resource_type !== WorkforceResourceType::OrganizationUnit->value) {
                    throw new InvalidRequirementProfileException(
                        'Department selector entity must be an organization_unit.',
                    );
                }

                $orgUnit = WorkforceOrganizationUnitProjection::query()
                    ->forCompany($tenantId, $companyEntityId)
                    ->where('workforce_entity_id', $selector->selectorEntityId)
                    ->first();

                if ($orgUnit === null) {
                    throw new InvalidRequirementProfileException(
                        "Department selector entity [{$selector->selectorEntityId}] does not belong to this company.",
                    );
                }
            }

            if ($selector->selectorType === SelectorType::Position) {
                if ($entity->resource_type !== WorkforceResourceType::Position->value) {
                    throw new InvalidRequirementProfileException(
                        'Position selector entity must be a position.',
                    );
                }

                $position = WorkforcePositionProjection::query()
                    ->forCompany($tenantId, $companyEntityId)
                    ->where('workforce_entity_id', $selector->selectorEntityId)
                    ->first();

                if ($position === null) {
                    throw new InvalidRequirementProfileException(
                        "Position selector entity [{$selector->selectorEntityId}] does not belong to this company.",
                    );
                }
            }
        }
    }

    /**
     * @param  list<RequirementItemDraft>  $items
     */
    private function assertItems(int $tenantId, int $companyEntityId, array $items): void
    {
        if (count($items) === 0) {
            throw new InvalidRequirementProfileException('A requirement profile needs at least one skill requirement.');
        }

        $skillIds = array_map(fn (RequirementItemDraft $item): int => $item->skillId, $items);
        if (count(array_unique($skillIds)) !== count($skillIds)) {
            throw new InvalidRequirementProfileException('Each skill may appear only once in a requirement profile.');
        }

        $sequences = array_map(fn (RequirementItemDraft $item): int => $item->sequence, $items);
        sort($sequences);
        if ($sequences !== range(1, count($items))) {
            throw new InvalidRequirementProfileException('Requirement sequences must be contiguous and start at 1.');
        }

        foreach ($items as $item) {
            if ($item->requiredLevel < 0 || $item->requiredLevel > 5) {
                throw new InvalidRequirementProfileException('Required level must be between 0 and 5.');
            }

            if ($item->weightPercent < 0) {
                throw new InvalidRequirementProfileException('Weight percent must be non-negative.');
            }

            if ($item->reassessmentMonths !== null && $item->reassessmentMonths <= 0) {
                throw new InvalidRequirementProfileException('Reassessment months must be a positive whole number.');
            }

            $skill = Skill::query()->forCompany($tenantId, $companyEntityId)->find($item->skillId);
            if ($skill === null) {
                throw new InvalidRequirementProfileException(
                    "Skill [{$item->skillId}] was not found in this company.",
                );
            }

            if ($skill->active === false) {
                throw new InvalidRequirementProfileException(
                    "Skill [{$item->skillId}] is inactive and cannot be added to a requirement profile.",
                );
            }
        }
    }

    /**
     * @param  Collection<int, RequirementProfileSelector>  $newSelectors
     */
    private function assertNoOverlap(int $tenantId, int $companyEntityId, RequirementProfile $newProfile, Collection $newSelectors): void
    {
        $publishedProfiles = RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('status', RequirementProfileStatus::Published->value)
            ->where('id', '!=', $newProfile->getKey())
            ->where('code', '!=', $newProfile->code)
            ->whereNull('retired_at')
            ->get();

        foreach ($publishedProfiles as $existingProfile) {
            $existingSelectors = RequirementProfileSelector::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('profile_id', $existingProfile->getKey())
                ->get();

            if ($this->selectorsCanOverlap($newSelectors, $existingSelectors)) {

                throw new InvalidRequirementProfileException(
                    "Profile [{$newProfile->code}] v{$newProfile->version} overlaps with published profile [{$existingProfile->code}] v{$existingProfile->version}. "
                    .'Overlapping profiles must be refined or the existing profile retired before publishing.',
                );
            }
        }
    }

    /**
     * @param  Collection<int, RequirementProfileSelector>  $selectorsA
     * @param  Collection<int, RequirementProfileSelector>  $selectorsB
     */
    private function selectorsCanOverlap(Collection $selectorsA, Collection $selectorsB): bool
    {
        $typeMapA = $selectorsA->groupBy('selector_type');
        $typeMapB = $selectorsB->groupBy('selector_type');

        foreach ($typeMapA as $type => $aSelectors) {
            if (! isset($typeMapB[$type])) {
                continue;
            }

            $aEntityIds = $aSelectors->pluck('selector_entity_id')->filter()->unique()->values()->all();
            $bEntityIds = $typeMapB[$type]->pluck('selector_entity_id')->filter()->unique()->values()->all();

            if ($aEntityIds !== [] && $bEntityIds !== [] && array_intersect($aEntityIds, $bEntityIds) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<RequirementItem>  $items
     */
    private function assertPublishableItems(array $items): void
    {
        $totalWeight = array_sum(array_map(fn (RequirementItem $item): float => (float) $item->weight_percent, $items));

        if (abs($totalWeight - 100.0) > self::WEIGHT_TOLERANCE) {
            throw new InvalidRequirementProfileException(
                'Requirement weights must total 100%. Current total: '.number_format($totalWeight, 2).'%',
            );
        }
    }

    private function assertCompanyEntity(int $tenantId, int $companyEntityId): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($companyEntityId);

        if ($entity === null || $entity->resource_type !== WorkforceResourceType::Company->value) {
            throw new InvalidRequirementProfileException(
                'A requirement profile must belong to an existing company workforce entity in this tenant.',
            );
        }
    }

    private function assertEmployeeEntity(int $tenantId, int $employeeEntityId): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($employeeEntityId);

        if ($entity === null || $entity->resource_type !== WorkforceResourceType::Employee->value) {
            throw new InvalidRequirementProfileException(
                'Profile owner must be an existing employee workforce entity in this tenant.',
            );
        }
    }
}
