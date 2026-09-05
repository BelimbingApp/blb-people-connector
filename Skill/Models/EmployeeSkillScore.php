<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality;
use App\Domains\PeopleConnector\Skill\Enums\SkillCoverageState;
use Carbon\CarbonInterface;

/**
 * Current valid skill level for an employee, projected from finalized assessment history.
 * Never overwrite by mutating a finalized assessment — only via a new finalized source row.
 * Expired validity windows never count as current coverage.
 */
class EmployeeSkillScore extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_employee_scores';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected function casts(): array
    {
        return [
            'requirement_version' => 'integer',
            'required_level' => 'integer',
            'current_level' => 'integer',
            'gap' => 'integer',
            'mandatory_gate' => 'boolean',
            'criticality' => RequirementCriticality::class,
            'assessed_at' => 'datetime',
            'next_assessment_due' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function coverageState(?CarbonInterface $asOf = null): SkillCoverageState
    {
        $asOf = ($asOf ?? now())->startOfDay();
        $deadline = $this->valid_until ?? $this->next_assessment_due;

        if ($deadline === null) {
            return SkillCoverageState::Current;
        }

        if ($deadline->startOfDay()->lt($asOf)) {
            return SkillCoverageState::Expired;
        }

        if ($deadline->startOfDay()->lte($asOf->copy()->addDays(30))) {
            return SkillCoverageState::DueSoon;
        }

        return SkillCoverageState::Current;
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'employee_skill_score', 'id' => $this->getKey()];
    }
}
