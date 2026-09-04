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

/**
 * One employee skill assessment history row. Finalized rows are immutable;
 * corrections insert a superseding row and refresh the current-score projection.
 */
class SkillAssessment extends TenantOwnedModel implements ReferencesWorkforceEntities
{
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
        };

        static::updating($guard);
        static::deleting($guard);
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

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_assessment', 'id' => $this->getKey()];
    }
}
