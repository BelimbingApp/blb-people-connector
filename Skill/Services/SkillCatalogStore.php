<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Events\SkillDeactivated;
use App\Domains\PeopleConnector\Skill\Events\SkillDefined;
use App\Domains\PeopleConnector\Skill\Events\SkillReactivated;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Exceptions\SkillCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;

/**
 * Skill-owned write path for the catalog. Tenant scoping comes from
 * TenantContext, and — because tenant scoping alone is NOT isolation
 * (blb-people-connector#6) — every lookup is additionally bound to one
 * company workforce entity: callers name the company they act for and a
 * record in another company's catalog is simply not found. Company,
 * department, and owner references are validated against the connector's
 * workforce identity spine (the #26 seam), and the composite (id, tenant_id)
 * foreign keys enforce the tenant half at the schema level too.
 */
class SkillCatalogStore
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,79}$/';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function defineCategory(int $companyEntityId, string $code, string $name, ?string $description = null): SkillCategory
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $this->assertCode($code, 'category');
        $this->assertEntity($tenantId, $companyEntityId, WorkforceResourceType::Company, 'company_entity_id');

        if (SkillCategory::query()->forCompany($tenantId, $companyEntityId)->where('code', $code)->exists()) {
            throw new InvalidSkillCatalogException("Skill category code [$code] already exists for this company.");
        }

        return SkillCategory::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'active' => true,
        ]);
    }

    public function editCategory(int $companyEntityId, int $categoryId, string $name, ?string $description = null): SkillCategory
    {
        $category = $this->requireCategory($companyEntityId, $categoryId);

        if (trim($name) === '') {
            throw new InvalidSkillCatalogException('A skill category needs a name.');
        }

        $category->update(['name' => $name, 'description' => $description]);

        return $category;
    }

    public function defineSkill(int $companyEntityId, SkillDraft $draft): Skill
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $this->assertCode($draft->code, 'skill');
        $this->assertEntity($tenantId, $companyEntityId, WorkforceResourceType::Company, 'company_entity_id');
        $this->assertDraft($tenantId, $companyEntityId, $draft);

        if (Skill::query()->forCompany($tenantId, $companyEntityId)->where('code', $draft->code)->exists()) {
            throw new InvalidSkillCatalogException("Skill code [{$draft->code}] already exists for this company.");
        }

        $skill = Skill::query()->create(
            $this->attributesFor($draft) + [
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'code' => $draft->code,
            ],
        );

        event(new SkillDefined($tenantId, (int) $skill->getKey(), $skill->code, created: true));

        return $skill;
    }

    /**
     * Revise a skill. The code is the stable company-scoped Skill ID and
     * cannot change; a draft carrying a different code is refused (and the
     * column is additionally guarded at the model and database layers).
     */
    public function reviseSkill(int $companyEntityId, int $skillId, SkillDraft $draft): Skill
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $skill = $this->requireSkill($companyEntityId, $skillId);

        if ($draft->code !== $skill->code) {
            throw new InvalidSkillCatalogException(
                "Skill code [{$skill->code}] is stable and cannot be changed to [{$draft->code}]. Deactivate this skill and define a new one instead.",
            );
        }

        $this->assertDraft($tenantId, $companyEntityId, $draft);

        $skill->update($this->attributesFor($draft));

        event(new SkillDefined($tenantId, (int) $skill->getKey(), $skill->code, created: false));

        return $skill;
    }

    public function deactivateSkill(int $companyEntityId, int $skillId): Skill
    {
        $skill = $this->requireSkill($companyEntityId, $skillId);

        if ($skill->active) {
            $skill->update(['active' => false]);
            event(new SkillDeactivated((int) $skill->tenant_id, (int) $skill->getKey(), $skill->code));
        }

        return $skill;
    }

    public function reactivateSkill(int $companyEntityId, int $skillId): Skill
    {
        $skill = $this->requireSkill($companyEntityId, $skillId);

        if (! $skill->category()->first()?->active) {
            throw new InvalidSkillCatalogException('Reactivate the skill category before reactivating its skills.');
        }

        if (! $skill->active) {
            $skill->update(['active' => true]);
            event(new SkillReactivated((int) $skill->tenant_id, (int) $skill->getKey(), $skill->code));
        }

        return $skill;
    }

    public function deactivateCategory(int $companyEntityId, int $categoryId): SkillCategory
    {
        $category = $this->requireCategory($companyEntityId, $categoryId);

        if ($category->skills()->where('active', true)->exists()) {
            throw new InvalidSkillCatalogException(
                "Skill category [{$category->code}] still has active skills; deactivate or recategorize them first.",
            );
        }

        $category->update(['active' => false]);

        return $category;
    }

    public function reactivateCategory(int $companyEntityId, int $categoryId): SkillCategory
    {
        $category = $this->requireCategory($companyEntityId, $categoryId);
        $category->update(['active' => true]);

        return $category;
    }

    private function requireSkill(int $companyEntityId, int $skillId): Skill
    {
        return Skill::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->find($skillId)
            ?? throw new SkillCatalogRecordNotFoundException("Skill [$skillId] was not found.");
    }

    private function requireCategory(int $companyEntityId, int $categoryId): SkillCategory
    {
        return SkillCategory::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->find($categoryId)
            ?? throw new SkillCatalogRecordNotFoundException("Skill category [$categoryId] was not found.");
    }

    /** @return array<string, mixed> */
    private function attributesFor(SkillDraft $draft): array
    {
        return [
            'category_id' => $draft->categoryId,
            'name' => $draft->name,
            'definition' => $draft->definition,
            'scope' => $draft->scope,
            'department_entity_id' => $draft->departmentEntityId,
            'critical_classification' => $draft->criticalClassification,
            'evidence_guide' => $draft->evidenceGuide,
            'default_assessment_method' => $draft->defaultAssessmentMethod,
            'default_reassessment_months' => $draft->defaultReassessmentMonths,
            'owner_employee_entity_id' => $draft->ownerEmployeeEntityId,
            'active' => $draft->active,
        ];
    }

    private function assertDraft(int $tenantId, int $companyEntityId, SkillDraft $draft): void
    {
        if (trim($draft->name) === '' || trim($draft->definition) === '') {
            throw new InvalidSkillCatalogException('A skill needs a name and a definition/standard.');
        }

        if ($draft->defaultReassessmentMonths !== null && $draft->defaultReassessmentMonths < 1) {
            throw new InvalidSkillCatalogException('Reassessment cadence must be a positive whole number of months.');
        }

        if ($draft->scope === SkillScope::Department && $draft->departmentEntityId === null) {
            throw new InvalidSkillCatalogException('A department-scoped skill must name its department.');
        }

        if ($draft->scope === SkillScope::Shared && $draft->departmentEntityId !== null) {
            throw new InvalidSkillCatalogException('A shared skill cannot be pinned to a department.');
        }

        $category = SkillCategory::query()->forCompany($tenantId, $companyEntityId)->find($draft->categoryId);
        if ($category === null) {
            throw new InvalidSkillCatalogException('The skill category must belong to the same company catalog.');
        }
        if (! $category->active && $draft->active) {
            throw new InvalidSkillCatalogException("Skill category [{$category->code}] is inactive; activate it first or pick another.");
        }

        if ($draft->departmentEntityId !== null) {
            $this->assertEntity($tenantId, $draft->departmentEntityId, WorkforceResourceType::OrganizationUnit, 'department_entity_id');
        }

        if ($draft->ownerEmployeeEntityId !== null) {
            $this->assertEntity($tenantId, $draft->ownerEmployeeEntityId, WorkforceResourceType::Employee, 'owner_employee_entity_id');
        }
    }

    private function assertCode(string $code, string $kind): void
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidSkillCatalogException(
                "A $kind code must be 1-80 lowercase letters, digits, dots, dashes, or underscores, starting with a letter or digit.",
            );
        }
    }

    private function assertEntity(int $tenantId, int $entityId, WorkforceResourceType $type, string $field): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($entityId);

        if ($entity === null || $entity->resource_type !== $type->value) {
            throw new InvalidSkillCatalogException(
                "[$field] must reference an existing {$type->value} workforce entity in this tenant.",
            );
        }
    }
}
