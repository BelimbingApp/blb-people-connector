<?php

namespace App\Domains\PeopleConnector\Training\Livewire\Catalog;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Services\TrainingAudience;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Company-scoped course administration; scheduling remains a separate screen. */
final class Index extends Component
{
    public ?int $companyEntityId = null;

    public ?int $editingCourseId = null;

    /** @var array<string, mixed> */
    public array $courseForm = [];

    /** @var array<int, string>|null */
    private ?array $companies = null;

    public function mount(TrainingAudience $audience): void
    {
        $companies = $this->allowedCompanies($audience);
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    public function selectCompany(int $companyEntityId, TrainingAudience $audience): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies($audience)), 404);

        $this->companyEntityId = $companyEntityId;
        $this->cancelCourse();
    }

    public function startCourse(TrainingAudience $audience): void
    {
        $this->openCourse(null, $audience);
    }

    public function editCourse(int $courseId, TrainingAudience $audience): void
    {
        $this->openCourse($courseId, $audience);
    }

    private function openCourse(?int $courseId, TrainingAudience $audience): void
    {
        $company = $this->managedCompany($audience);
        $course = $courseId === null ? null : $this->courses($company)->firstWhere('id', $courseId);
        abort_if($courseId !== null && $course === null, 404);

        $this->editingCourseId = $course === null ? null : (int) $course->id;
        $this->courseForm = [
            'code' => $course?->code ?? '',
            'title' => $course?->title ?? '',
            'description' => $course?->description ?? '',
            'delivery_mode' => ($course?->delivery_mode ?? DeliveryMode::InternalClassroom)->value,
            'skill_ids' => $course?->skillIds() ?? [],
            'internal_trainer_employee_entity_id' => $course?->internal_trainer_employee_entity_id,
        ];
    }

    public function cancelCourse(): void
    {
        $this->reset('editingCourseId', 'courseForm');
    }

    public function saveCourse(TrainingAudience $audience, TrainingCatalogStore $store): void
    {
        $company = $this->managedCompany($audience);
        $validated = $this->validate([
            'courseForm.code' => ['required', 'string', 'max:80'],
            'courseForm.title' => ['required', 'string', 'max:255'],
            'courseForm.description' => ['nullable', 'string'],
            'courseForm.delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'courseForm.skill_ids' => ['required', 'array', 'min:1'],
            'courseForm.skill_ids.*' => ['integer', 'distinct'],
            'courseForm.internal_trainer_employee_entity_id' => ['nullable', 'integer'],
        ]);
        $form = $validated['courseForm'];
        $draft = new TrainingCourseDraft(
            code: trim($form['code']),
            title: trim($form['title']),
            description: trim((string) ($form['description'] ?? '')) ?: null,
            deliveryMode: DeliveryMode::from($form['delivery_mode']),
            skillIds: array_map(intval(...), $form['skill_ids']),
            internalTrainerEmployeeEntityId: $form['internal_trainer_employee_entity_id'] === null || $form['internal_trainer_employee_entity_id'] === ''
                ? null : (int) $form['internal_trainer_employee_entity_id'],
        );

        try {
            if ($this->editingCourseId === null) {
                $store->defineCourse($company, $draft);
            } else {
                $store->reviseCourse($company, $this->editingCourseId, $draft);
            }
        } catch (InvalidTrainingCatalogException $exception) {
            $this->addError('courseForm', $exception->getMessage());

            return;
        }

        $this->cancelCourse();
        session()->flash('status', __('Training course saved.'));
    }

    public function toggleCourseActive(int $courseId, TrainingAudience $audience, TrainingCatalogStore $store): void
    {
        $company = $this->managedCompany($audience);
        $course = $this->courses($company)->firstWhere('id', $courseId);
        abort_if($course === null, 404);

        $course->active
            ? $store->deactivateCourse($company, $courseId)
            : $store->reactivateCourse($company, $courseId);
    }

    public function render(TrainingAudience $audience): View
    {
        $companies = $this->allowedCompanies($audience);
        $company = $this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)
            ? $this->companyEntityId : null;
        $tenant = $company === null ? null : app(TenantContext::class)->requireTenantId();

        return view('people-connector-training::livewire.catalog.index', [
            'companies' => $companies,
            'courses' => $company === null ? collect() : $this->courses($company),
            'skills' => $company === null ? collect() : Skill::query()->forCompany($tenant, $company)->where('active', true)->orderBy('name')->get(),
            'employees' => $company === null ? collect() : WorkforceEmployeeProjection::query()->forCompany($tenant, $company)->where('active', true)->orderBy('display_name')->get(),
            'canManage' => $company !== null && $audience->canManage(Auth::user(), $company),
            'deliveryModes' => DeliveryMode::cases(),
        ]);
    }

    /** @return array<int, string> */
    private function allowedCompanies(TrainingAudience $audience): array
    {
        return $this->companies ??= $audience->allowedCompanies(Auth::user());
    }

    private function managedCompany(TrainingAudience $audience): int
    {
        abort_if($this->companyEntityId === null, 404);
        $audience->authorizeManage(Auth::user(), $this->companyEntityId);

        return $this->companyEntityId;
    }

    private function courses(int $companyEntityId)
    {
        return TrainingCourse::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->orderBy('code')
            ->get();
    }
}
