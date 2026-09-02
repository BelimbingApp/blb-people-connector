<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One skill/competency definition in the company catalog, carrying the full
 * workbook parity fields. `code` is the stable company-scoped Skill ID and is
 * immutable after creation; employee, department, and company references are
 * connector workforce entity ids, never provider-model foreign keys.
 */
class Skill extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_skills';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('department_entity_id', WorkforceResourceType::OrganizationUnit),
            new WorkforceReference('owner_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Skill $skill): void {
            if ($skill->isDirty('code')) {
                throw new InvalidSkillCatalogException(
                    "Skill code [{$skill->getOriginal('code')}] is stable and cannot be changed.",
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'scope' => SkillScope::class,
            'critical_classification' => CriticalClassification::class,
            'default_assessment_method' => AssessmentMethod::class,
            'default_reassessment_months' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * No escape here, deliberately.
     *
     * A belongsTo constrains the parent's primary key, which is not
     * SkillCategory's company column, so this relation does not satisfy the
     * guard on its own. It used to carry an escape saying so — but an escape
     * covers the whole query including whatever a caller appends, and
     * `$skill->category()->orWhere('id', $otherId)` therefore read and wrote
     * another company's category. Leaving the guard on turns that into a
     * refusal.
     *
     * The cost, stated plainly: **lazy `$skill->category` throws.** Load it
     * with the company pinned instead, which every caller can do because every
     * caller already knows the company it is acting for:
     *
     *     Skill::query()->forCompany($tenantId, $companyEntityId)
     *         ->with(['category' => fn ($q) => $q->forCompany($tenantId, $companyEntityId)])
     *
     * That is one line longer and it cannot silently cross the boundary.
     *
     * @return BelongsTo<SkillCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SkillCategory::class, 'category_id');
    }

    public function isCritical(): bool
    {
        return $this->critical_classification !== null;
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill', 'id' => $this->getKey()];
    }
}
