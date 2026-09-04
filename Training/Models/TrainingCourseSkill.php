<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;

/**
 * One training-course-to-skill mapping. Inherits company ownership from
 * `course_id`, the same shape as ProficiencyScaleLevel inheriting from its
 * scale — see docs/contracts/company-ownership.md.
 */
class TrainingCourseSkill extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_training_course_skills';

    public $timestamps = false;

    public function companyOwnerColumn(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function companyScopeColumns(): array
    {
        return ['course_id'];
    }
}
