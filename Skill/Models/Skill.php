<?php

namespace App\Domains\PeopleConnector\Skill\Models;

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
class Skill extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_skills';

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
     * The escape is needed because a belongsTo constrains the parent's primary
     * key, which is not SkillCategory's company column. It is safe for the
     * relation as written: the skill was resolved for its company, and the
     * store refuses a category_id from any other one — though the database
     * does not, since the key is (category_id, tenant_id).
     *
     * The escape covers whatever a caller appends to this relation, including
     * an unbracketed orWhere. Do not append one.
     *
     * @return BelongsTo<SkillCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SkillCategory::class, 'category_id')
            ->withoutCompanyScope('Constrains the category primary key, which is not its company column; the skill was resolved for its company and the store refuses a category from another one.');
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
