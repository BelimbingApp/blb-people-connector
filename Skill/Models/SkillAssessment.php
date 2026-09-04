<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentResultBand;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentStatus;
use App\Domains\PeopleConnector\Skill\Enums\HodVerification;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Exceptions\FinalizedAssessmentImmutableException;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee skill assessment history row. Finalized rows are immutable;
 * corrections insert a superseding row and refresh the current-score projection.
 */
class SkillAssessment extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    private static int $lifecycleTransitionDepth = 0;

    /** @var list<string> */
    private const LIFECYCLE_COLUMNS = [
        'status',
        'hod_verification',
        'hod_verifier_user_id',
        'hod_verified_at',
        'hod_decision_notes',
        'finalized_at',
        'finalized_by_user_id',
    ];

    /** @var list<string> */
    private const SUBMITTED_COLUMNS = [
        'employee_entity_id',
        'skill_id',
        'requirement_reference',
        'requirement_version',
        'required_level',
        'criticality',
        'weight_percent',
        'mandatory_gate',
        'scale_id',
        'scale_version',
        'assessed_level',
        'gap',
        'weighted_gap',
        'priority_score',
        'result_band',
        'method',
        'cycle',
        'evidence',
        'notes',
        'assessor_user_id',
        'assessor_employee_entity_id',
        'assessed_at',
        'certificate_number',
        'valid_until',
        'next_assessment_due',
        'supersedes_assessment_id',
        ...self::LIFECYCLE_COLUMNS,
    ];

    use CompanyOwned;

    protected $table = 'people_connector_skill_assessments';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('assessor_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected function casts(): array
    {
        return [
            'requirement_version' => 'integer',
            'required_level' => 'integer',
            'criticality' => RequirementCriticality::class,
            'weight_percent' => 'decimal:2',
            'mandatory_gate' => 'boolean',
            'scale_version' => 'integer',
            'assessed_level' => 'integer',
            'gap' => 'integer',
            'weighted_gap' => 'decimal:2',
            'priority_score' => 'decimal:2',
            'result_band' => AssessmentResultBand::class,
            'method' => AssessmentMethod::class,
            'cycle' => AssessmentCycle::class,
            'status' => AssessmentStatus::class,
            'assessed_at' => 'datetime',
            'assessor_user_id' => 'integer',
            'assessor_employee_entity_id' => 'integer',
            'hod_verification' => HodVerification::class,
            'hod_verifier_user_id' => 'integer',
            'hod_verified_at' => 'immutable_datetime',
            'hod_decision_notes' => 'string',
            'valid_until' => 'date',
            'next_assessment_due' => 'date',
            'finalized_at' => 'datetime',
            'finalized_by_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (SkillAssessment $assessment): void {
            if ($assessment->getOriginal('finalized_at') !== null) {
                throw new FinalizedAssessmentImmutableException(
                    "Finalized assessment {$assessment->getKey()} cannot be modified; supersede with a new row.",
                );
            }

            $originalStatus = $assessment->getOriginal('status');
            $originalStatus = $originalStatus instanceof AssessmentStatus
                ? $originalStatus
                : AssessmentStatus::tryFrom((string) $originalStatus);

            if ($originalStatus === AssessmentStatus::Returned
                || $originalStatus === AssessmentStatus::Finalized) {
                throw new InvalidAssessmentException(
                    "Assessment {$assessment->getKey()} is historical and can only be corrected by inserting a new row.",
                );
            }

            $dirty = array_keys($assessment->getDirty());
            if (self::$lifecycleTransitionDepth === 0
                && array_intersect($dirty, self::LIFECYCLE_COLUMNS) !== []) {
                throw new InvalidAssessmentException(
                    'Assessment lifecycle changes must go through AssessmentStore workflow methods.',
                );
            }

            if (self::$lifecycleTransitionDepth === 0
                && $originalStatus !== AssessmentStatus::Draft
                && array_intersect($dirty, self::SUBMITTED_COLUMNS) !== []) {
                throw new InvalidAssessmentException(
                    'Submitted assessment facts are immutable; submit a governed correction instead.',
                );
            }
        };

        static::updating($guard);
        static::deleting(function (SkillAssessment $assessment): void {
            if ($assessment->getOriginal('finalized_at') !== null) {
                throw new FinalizedAssessmentImmutableException(
                    "Finalized assessment {$assessment->getKey()} cannot be deleted; supersede with a new row.",
                );
            }

            $status = $assessment->getOriginal('status');
            $status = $status instanceof AssessmentStatus
                ? $status
                : AssessmentStatus::tryFrom((string) $status);

            if ($status !== AssessmentStatus::Draft) {
                throw new InvalidAssessmentException(
                    "Assessment {$assessment->getKey()} is historical and cannot be deleted.",
                );
            }
        });
    }

    public static function withinLifecycleTransition(Closure $callback): mixed
    {
        self::$lifecycleTransitionDepth++;

        try {
            return $callback();
        } finally {
            self::$lifecycleTransitionDepth--;
        }
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null || $this->status === AssessmentStatus::Finalized;
    }

    public function isAwaitingHodVerification(): bool
    {
        return $this->status === AssessmentStatus::PendingHodVerification
            && $this->hod_verification === HodVerification::Pending;
    }

    public function isHodVerified(): bool
    {
        return $this->hod_verification === HodVerification::Verified
            && $this->hod_verifier_user_id !== null
            && $this->hod_verified_at !== null;
    }

    /** @return HasMany<AssessmentDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(AssessmentDecision::class, 'assessment_id');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_assessment', 'id' => $this->getKey()];
    }
}
