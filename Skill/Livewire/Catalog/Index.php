<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\Catalog;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
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
 * HODs and evaluators (view capability). Every query and store call is bound
 * to one company workforce entity the acting user may act for, resolved by
 * the connector's CompanyAttribution service — companyEntityId is a client-writable Livewire property,
 * so it is re-checked on every action, never trusted. With no accessible
 * company the page states that honestly instead of pretending a catalog
 * exists.
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

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();

        $companies = $this->allowedCompanies();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        $this->companyEntityId = $companyEntityId;
        $this->reset('editingSkillId', 'skillForm', 'filterCategoryId');
    }

    public function installStarterPack(SkillCatalogDefaults $defaults): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $defaults->install($companyEntityId);
    }

    public function startSkill(?int $skillId = null): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $skill = $skillId === null ? null : $this->skills($companyEntityId)->firstWhere('id', $skillId);
        $this->editingSkillId = $skill?->id;
        $this->skillForm = [
            'code' => $skill->code ?? '',
            'name' => $skill->name ?? '',
            'definition' => $skill->definition ?? '',
            'category_id' => $skill->category_id ?? $this->categories($companyEntityId)->first()?->id,
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
        $companyEntityId = $this->authorizedCompanyForManage();

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
                $store->defineSkill($companyEntityId, $draft);
            } else {
                $store->reviseSkill($companyEntityId, $this->editingSkillId, $draft);
            }
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skillForm', $exception->getMessage());

            return;
        }

        $this->reset('editingSkillId', 'skillForm');
    }

    public function toggleSkillActive(int $skillId, SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $skill = $this->skills($companyEntityId)->firstWhere('id', $skillId);
        abort_if($skill === null, 404);

        try {
            $skill->active
                ? $store->deactivateSkill($companyEntityId, $skillId)
                : $store->reactivateSkill($companyEntityId, $skillId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skills', $exception->getMessage());
        }
    }

    public function saveCategory(SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        try {
            $store->defineCategory(
                $companyEntityId,
                trim((string) $this->newCategoryCode),
                trim((string) $this->newCategoryName),
            );
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());

            return;
        }

        $this->reset('newCategoryCode', 'newCategoryName');
    }

    public function renameCategory(int $categoryId, string $name, SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        try {
            $store->editCategory($companyEntityId, $categoryId, trim($name));
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());
        }
    }

    public function toggleCategoryActive(int $categoryId, SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $category = $this->categories($companyEntityId)->firstWhere('id', $categoryId);
        abort_if($category === null, 404);

        try {
            $category->active
                ? $store->deactivateCategory($companyEntityId, $categoryId)
                : $store->reactivateCategory($companyEntityId, $categoryId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());
        }
    }

    public function publishScale(int $scaleId, ProficiencyScaleStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $store->publish($companyEntityId, $scaleId);
    }

    public function draftNewScaleVersion(int $scaleId, ProficiencyScaleStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $store->newDraftFrom($companyEntityId, $scaleId);
    }

    public function render(): View
    {
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)
            ? $this->companyEntityId
            : null;

        return view('people-connector-skill::livewire.catalog.index', [
            'companies' => $companies,
            'categories' => $companyEntityId === null ? collect() : $this->categories($companyEntityId),
            'skills' => $companyEntityId === null ? collect() : $this->filteredSkills($companyEntityId),
            'scales' => $companyEntityId === null ? collect() : $this->scales($companyEntityId),
            'canManage' => $this->canManage(),
            'scopeOptions' => SkillScope::cases(),
            'methodOptions' => AssessmentMethod::cases(),
            'classificationOptions' => CriticalClassification::cases(),
        ]);
    }

    /**
     * Resolved once per request: the picker, every action guard, and the
     * render pass all ask the same question of the same actor.
     *
     * @return array<int, string> company entity id => display name
     */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(CompanyAttribution::class)
            ->allowedCompanyEntities(Auth::user());
    }

    /**
     * The single authorization funnel for every mutating action: manage
     * capability plus proof the actor may act for the selected company.
     */
    private function authorizedCompanyForManage(): int
    {
        $this->authorizeManage();
        abort_if($this->companyEntityId === null, 404);
        abort_unless(array_key_exists($this->companyEntityId, $this->allowedCompanies()), 404);

        return $this->companyEntityId;
    }

    private function categories(int $companyEntityId)
    {
        return SkillCategory::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Skill::category() carries no escape, so the eager load has to pin the
     * company itself — which costs nothing here, because this method was
     * handed the company it is allowed to act for. A category belonging to
     * anyone else simply does not load, and the view renders a blank cell
     * rather than another company's category name.
     */
    private function skills(int $companyEntityId)
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->with(['category' => fn ($query) => $query->forCompany($tenantId, $companyEntityId)])
            ->orderBy('code')
            ->get();
    }

    private function filteredSkills(int $companyEntityId)
    {
        return $this->skills($companyEntityId)
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

    private function scales(int $companyEntityId)
    {
        return ProficiencyScale::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
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

    private function authorizeView(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people-connector.skill.catalog.view',
        );
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people-connector.skill.catalog.manage',
        );
    }
}
