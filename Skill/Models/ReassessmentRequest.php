<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentCycle;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestSource;
use App\Domains\PeopleConnector\Skill\Enums\ReassessmentRequestStatus;
use Carbon\CarbonInterface;

/**
 * Assigned post-intervention or renewal reassessment work.
 * Fulfillment is a separate finalized Assessment Log row; this request never mutates scores.
 */
final class ReassessmentRequest extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_reassessment_requests';

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
            'target_level' => 'integer',
            'cycle' => AssessmentCycle::class,
            'source' => ReassessmentRequestSource::class,
            'status' => ReassessmentRequestStatus::class,
            'due_date' => 'date',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function daysUntilDue(?CarbonInterface $today = null): int
    {
        $today ??= now();

        return (int) $today->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'reassessment_request', 'id' => $this->getKey()];
    }
}
