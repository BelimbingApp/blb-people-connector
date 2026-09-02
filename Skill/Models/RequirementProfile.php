<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned requirement profile defining what skills a position requires.
 * Once published, the profile and its items are immutable historical policy;
 * edits produce a new version with an effective date. Employee movement does
 * not rewrite past requirements or assessments.
 */
class RequirementProfile extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_requirement_profiles';

    protected static function booted(): void
    {
        static::updating(function (RequirementProfile $profile): void {
            $original = $profile->getOriginal('status');
            $original = $original instanceof RequirementProfileStatus
                ? $original
                : RequirementProfileStatus::from((string) $original);

            if ($original === RequirementProfileStatus::Draft) {
                return;
            }

            if ($original === RequirementProfileStatus::Published && $profile->isRetireOnlyChange()) {
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
}
