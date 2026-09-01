<?php

namespace App\Domains\PeopleConnector\Skill\Models;

use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Models\Concerns\CompanyOwned;
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

    /** @return BelongsTo<SkillCategory, $this> */
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
