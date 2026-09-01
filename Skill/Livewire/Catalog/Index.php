<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\Catalog;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\CriticalClassification;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogDefaults;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Catalog administration for HR (manage capability) with a read-only view for
 * HODs and evaluators (view capability). Operates on the company's connector
 * workforce entity; with no synchronized company the page states that honestly
 * instead of pretending a catalog exists.
 */
class Index extends Component
{
    public string $tab = 'skills';

    public ?int $companyEntityId = null;

    public string $search = '';

    public ?int $filterCategoryId = null;

    public bool $criticalOnly = false;

    public bool $includeInactive = false;

    // Skill form state (null id = create).
    public ?int $editingSkillId = null;

    /** @var array<string, mixed> */
    public array $skillForm = [];

    public ?string $newCategoryCode = null;

    public ?string $newCategoryName = null;

    public function mount(): void
    {
        $companies = $this->companyEntities();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    public function selectCompany(int $companyEntityId): void
    {
        $companies = $this->companyEntities();
        abort_unless(array_key_exists($companyEntityId, $companies), 404);

        $this->companyEntityId = $companyEntityId;
        $this->reset('editingSkillId', 'skillForm', 'filterCategoryId');
    }

    public function installStarterPack(SkillCatalogDefaults $defaults): void
    {
        $this->authorizeManage();
        abort_if($this->companyEntityId === null, 404);

        $defaults->install($this->companyEntityId);
    }

    public function startSkill(?int $skillId = null): void
    {
        $this->authorizeManage();

        $skill = $skillId === null ? null : $this->skills()->firstWhere('id', $skillId);
        $this->editingSkillId = $skill?->id;
        $this->skillForm = [
            'code' => $skill->code ?? '',
            'name' => $skill->name ?? '',
            'definition' => $skill->definition ?? '',
            'category_id' => $skill->category_id ?? $this->categories()->first()?->id,
            'scope' => ($skill->scope ?? SkillScope::Shared)->value,
            'critical_classification' => $skill?->critical_classification?->value,
            'evidence_guide' => $skill->evidence_guide ?? '',
            'default_assessment_method' => ($skill->default_assessment_method ?? AssessmentMethod::DirectObservation)->value,
            'default_reassessment_months' => $skill->default_reassessment_months ?? null,
        ];
    }

    public function cancelSkill(): void
    {
        $this->reset('editingSkillId', 'skillForm');
    }

    public function saveSkill(SkillCatalogStore $store): void
    {
        $this->authorizeManage();
        abort_if($this->companyEntityId === null, 404);

        $months = $this->skillForm['default_reassessment_months'] ?? null;
        $classification = $this->skillForm['critical_classification'] ?? null;

        try {
            $draft = new SkillDraft(
                code: trim((string) ($this->skillForm['code'] ?? '')),
                name: trim((string) ($this->skillForm['name'] ?? '')),
                definition: trim((string) ($this->skillForm['definition'] ?? '')),
                categoryId: (int) ($this->skillForm['category_id'] ?? 0),
                scope: SkillScope::from((string) ($this->skillForm['scope'] ?? SkillScope::Shared->value)),
                criticalClassification: $classification === null || $classification === ''
                    ? null
                    : CriticalClassification::from((string) $classification),
                evidenceGuide: trim((string) ($this->skillForm['evidence_guide'] ?? '')) ?: null,
                defaultAssessmentMethod: AssessmentMethod::from(
                    (string) ($this->skillForm['default_assessment_method'] ?? AssessmentMethod::DirectObservation->value),
                ),
                defaultReassessmentMonths: $months === null || $months === '' ? null : (int) $months,
            );

            if ($this->editingSkillId === null) {
                $store->defineSkill($this->companyEntityId, $draft);
            } else {
                $store->reviseSkill($this->editingSkillId, $draft);
            }
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skillForm', $exception->getMessage());

            return;
        }

        $this->reset('editingSkillId', 'skillForm');
    }

    public function toggleSkillActive(int $skillId, SkillCatalogStore $store): void
    {
        $this->authorizeManage();

        $skill = $this->skills()->firstWhere('id', $skillId);
        abort_if($skill === null, 404);

        try {
            $skill->active ? $store->deactivateSkill($skillId) : $store->reactivateSkill($skillId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skills', $exception->getMessage());
        }
    }

    public function saveCategory(SkillCatalogStore $store): void
    {
        $this->authorizeManage();
        abort_if($this->companyEntityId === null, 404);

        try {
            $store->defineCategory(
                $this->companyEntityId,
                trim((string) $this->newCategoryCode),
                trim((string) $this->newCategoryName),
            );
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());

            return;
        }

        $this->reset('newCategoryCode', 'newCategoryName');
    }

    public function deactivateCategory(int $categoryId, SkillCatalogStore $store): void
    {
        $this->authorizeManage();

        try {
            $store->deactivateCategory($categoryId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());
        }
    }

    public function publishScale(int $scaleId, ProficiencyScaleStore $store): void
    {
        $this->authorizeManage();
        $store->publish($scaleId);
    }

    public function draftNewScaleVersion(int $scaleId, ProficiencyScaleStore $store): void
    {
        $this->authorizeManage();
        $store->newDraftFrom($scaleId);
    }

    public function render(): View
    {
        $canManage = $this->canManage();
        $categories = $this->companyEntityId === null ? collect() : $this->categories();

        return view('people-connector-skill::livewire.catalog.index', [
            'companies' => $this->companyEntities(),
            'categories' => $categories,
            'skills' => $this->companyEntityId === null ? collect() : $this->filteredSkills(),
            'scales' => $this->companyEntityId === null ? collect() : $this->scales(),
            'canManage' => $canManage,
            'scopeOptions' => SkillScope::cases(),
            'methodOptions' => AssessmentMethod::cases(),
            'classificationOptions' => CriticalClassification::cases(),
        ]);
    }

    /** @return array<int, string> company entity id => display name */
    private function companyEntities(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return WorkforceCompanyProjection::query()
            ->forTenant($tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (WorkforceCompanyProjection $company): array => [
                (int) $company->workforce_entity_id => (string) $company->name,
            ])
            ->all();
    }

    private function categories()
    {
        return SkillCategory::query()
            ->forTenant(app(TenantContext::class)->requireTenantId())
            ->where('company_entity_id', $this->companyEntityId)
            ->orderBy('name')
            ->get();
    }

    private function skills()
    {
        return Skill::query()
            ->forTenant(app(TenantContext::class)->requireTenantId())
            ->where('company_entity_id', $this->companyEntityId)
            ->with('category')
            ->orderBy('code')
            ->get();
    }

    private function filteredSkills()
    {
        return $this->skills()
            ->when(! $this->includeInactive, fn ($skills) => $skills->where('active', true))
            ->when($this->filterCategoryId !== null, fn ($skills) => $skills->where('category_id', $this->filterCategoryId))
            ->when($this->criticalOnly, fn ($skills) => $skills->filter->isCritical())
            ->when(trim($this->search) !== '', function ($skills) {
                $needle = mb_strtolower(trim($this->search));

                return $skills->filter(
                    fn (Skill $skill): bool => str_contains(mb_strtolower($skill->code.' '.$skill->name), $needle),
                );
            })
            ->values();
    }

    private function scales()
    {
        return ProficiencyScale::query()
            ->forTenant(app(TenantContext::class)->requireTenantId())
            ->where('company_entity_id', $this->companyEntityId)
            ->with('levels')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get();
    }

    private function canManage(): bool
    {
        return app(AuthorizationService::class)->can(
            Actor::forUser(Auth::user()),
            'people-connector.skill.catalog.manage',
        )->allowed;
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people-connector.skill.catalog.manage',
        );
    }
}
