<?php

namespace App\Domains\PeopleConnector\Skill\Livewire\RequirementProfile;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Skill\Models\RequirementItem;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Read-only, exact-version destination for governed workflow notifications. */
final class Show extends Component
{
    public RequirementProfile $profile;

    public function mount(int $profileId): void
    {
        $companies = app(SkillAudience::class)->allowedCompanies(
            Auth::user(),
            'people-connector.skill.catalog.view',
        );

        $this->profile = RequirementProfile::query()
            ->forTenant(app(TenantContext::class)->requireTenantId())
            ->whereIn('company_entity_id', array_keys($companies))
            ->findOrFail($profileId);
    }

    public function render(): View
    {
        $tenantId = (int) $this->profile->tenant_id;
        $companyEntityId = (int) $this->profile->company_entity_id;

        return view('people-connector-skill::livewire.requirement-profile.show', [
            'selectors' => RequirementProfileSelector::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('profile_id', $this->profile->getKey())
                ->orderBy('id')
                ->get(),
            'items' => RequirementItem::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('profile_id', $this->profile->getKey())
                ->with(['skill' => fn ($query) => $query->forCompany($tenantId, $companyEntityId)])
                ->orderBy('sequence')
                ->get(),
        ]);
    }
}
