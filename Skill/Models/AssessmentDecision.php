<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;

/** Immutable append-only record of every governed assessment decision. */
final class AssessmentDecision extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_connector_skill_assessment_decisions';

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new InvalidAssessmentException(
            'Assessment decisions are append-only.',
        );

        self::updating($immutable);
        self::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee)];
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_assessment_decision', 'id' => $this->getKey()];
    }
}
