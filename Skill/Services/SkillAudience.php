<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Enums\SelectorType;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector;
use App\Domains\PeopleConnector\Skill\Models\SkillActorBinding;
use App\Domains\PeopleConnector\Skill\Models\SkillAssessorAssignment;

/**
 * Narrows flat role grants to connector-owned company, department/team,
 * assignment, or self boundaries.
 *
 * Base GrantPolicy deliberately has no department attributes. Consequently a
 * functional capability is necessary but never sufficient here. Audience
 * capabilities declare why access exists, and this service resolves the rows
 * that reason permits. A grant_all role alone is rejected: platform
 * administration must not silently become HR administration.
 */
class SkillAudience
{
    public const HR = 'hr';

    public const HOD = 'hod';

    public const ASSESSOR = 'assessor';

    public const EMPLOYEE = 'employee';

    private const ROLE_CODES = [
        self::HR => 'people_hr',
        self::HOD => 'people_hod',
        self::ASSESSOR => 'people_assessor',
        self::EMPLOYEE => 'people_employee',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly CompanyAttribution $companies,
    ) {}

    /** @return array<int, string> */
    public function allowedCompanies(User $user, string $functionalCapability): array
    {
        $this->authorizeAudience($user, $functionalCapability);

        return $this->companies->allowedCompanyEntities($user);
    }

    public function mayManageCatalog(User $user, int $companyEntityId): bool
    {
        return $this->may($user, 'people-connector.skill.catalog.manage', $companyEntityId, [self::HR]);
    }

    /**
     * Resolve the concrete users responsible for the target workflow state.
     * The same deep audience checks used by transition guards are applied, so
     * stale bindings and sibling-company roles never become notifications.
     *
     * @return list<int>
     */
    public function requirementReviewerUserIds(
        RequirementProfile $profile,
        RequirementProfileStatus $target,
    ): array {
        $role = match ($target) {
            RequirementProfileStatus::PendingHodReview => self::HOD,
            RequirementProfileStatus::PendingHrReview, RequirementProfileStatus::Approved => self::HR,
            default => null,
        };

        if ($role === null) {
            return [];
        }

        $candidateIds = PrincipalRole::query()
            ->where('principal_type', PrincipalType::USER->value)
            ->whereHas('role', fn ($query) => $query->where('code', self::ROLE_CODES[$role]))
            ->pluck('principal_id')
            ->map(intval(...))
            ->unique();

        return User::query()->whereKey($candidateIds)
            ->get()
            ->filter(fn (User $user): bool => $role === self::HOD
                ? $this->mayReviewRequirementProfile($user, $profile)
                : $this->mayGovernRequirements($user, (int) $profile->company_entity_id))
            ->pluck('id')->map(intval(...))->values()->all();
    }

    public function authorizeCatalogManage(User $user, int $companyEntityId): void
    {
        if (! $this->mayManageCatalog($user, $companyEntityId)) {
            $this->deny();
        }
    }

    /**
     * @return list<int> workforce employee entity ids
     */
    public function visibleEmployeeEntityIds(User $user, int $companyEntityId, bool $manage): array
    {
        $capability = $manage
            ? 'people-connector.skill.assessment.manage'
            : 'people-connector.skill.assessment.view';
        $audiences = $this->authorizeAudience($user, $capability);

        return $this->scopedEmployeeEntityIds(
            $user,
            $companyEntityId,
            $audiences,
            includeAssessorAssignments: true,
            includeSelf: ! $manage,
        );
    }

    /** @return list<int> workforce employee entity ids */
    public function visibleDevelopmentActionEmployeeEntityIds(User $user, int $companyEntityId, bool $manage): array
    {
        $capability = $manage
            ? 'people-connector.skill.development-action.manage'
            : 'people-connector.skill.development-action.view';

        return $this->scopedEmployeeEntityIds(
            $user,
            $companyEntityId,
            $this->authorizeAudience($user, $capability),
            includeAssessorAssignments: false,
            includeSelf: false,
        );
    }

    /**
     * @return list<int>
     */
    public function visibleReassessmentEmployeeEntityIds(User $user, int $companyEntityId, bool $manage): array
    {
        $capability = $manage
            ? 'people-connector.skill.reassessment.manage'
            : 'people-connector.skill.reassessment.view';

        return $this->scopedEmployeeEntityIds(
            $user,
            $companyEntityId,
            $this->authorizeAudience($user, $capability),
            includeAssessorAssignments: true,
            includeSelf: false,
        );
    }

    /**
     * @param  list<string>  $audiences
     * @return list<int>
     */
    private function scopedEmployeeEntityIds(
        User $user,
        int $companyEntityId,
        array $audiences,
        bool $includeAssessorAssignments,
        bool $includeSelf,
    ): array {

        if (! $this->companies->mayActFor($user, $companyEntityId)) {
            return [];
        }

        $tenantId = $this->tenantContext->requireTenantId();
        $employees = WorkforceEmployeeProjection::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('active', true)
            ->whereIn('workforce_entity_id', WorkforceEntity::query()
                ->forTenant($tenantId)
                ->where('resource_type', 'employee')
                ->where('state', WorkforceEntity::STATE_ACTIVE)
                ->select('id'));

        if (in_array(self::HR, $audiences, true)) {
            return $employees->pluck('workforce_entity_id')->map(intval(...))->all();
        }

        $allowed = [];
        $binding = $this->activeBinding($user, $companyEntityId);

        if (in_array(self::HOD, $audiences, true) && $binding !== null) {
            $managerId = (int) $binding->employee_entity_id;
            $managed = (clone $employees)
                ->where('manager_entity_id', $managerId)
                ->pluck('workforce_entity_id')->map(intval(...))->all();
            $headed = (clone $employees)
                ->where('department_head_entity_id', $managerId)
                ->pluck('workforce_entity_id')->map(intval(...))->all();
            $allowed = [...$allowed, ...$managed, ...$headed];
        }

        if ($includeAssessorAssignments && in_array(self::ASSESSOR, $audiences, true)) {
            $now = now();
            $assigned = SkillAssessorAssignment::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('assessor_user_id', $user->getAuthIdentifier())
                ->where('effective_from', '<=', $now)
                ->whereRaw('(effective_to is null or effective_to > ?)', [$now])
                ->pluck('employee_entity_id')->map(intval(...))->all();

            $allowed = [...$allowed, ...((clone $employees)
                ->whereIn('workforce_entity_id', $assigned)
                ->pluck('workforce_entity_id')->map(intval(...))->all())];
        }

        if ($includeSelf && in_array(self::EMPLOYEE, $audiences, true) && $binding !== null) {
            $allowed[] = (int) $binding->employee_entity_id;
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Resolve department/team ownership for consumers whose records are
     * department-owned rather than employee-owned (for example training plans).
     *
     * @return list<int> workforce organization-unit entity ids
     */
    public function visibleOrganizationUnitEntityIds(
        User $user,
        int $companyEntityId,
        string $functionalCapability,
    ): array {
        $audiences = $this->authorizeAudience($user, $functionalCapability);
        if (! $this->companies->mayActFor($user, $companyEntityId)) {
            return [];
        }

        $tenantId = $this->tenantContext->requireTenantId();
        $units = WorkforceOrganizationUnitProjection::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('active', true);

        if (in_array(self::HR, $audiences, true)) {
            return $units->pluck('workforce_entity_id')->map(intval(...))->all();
        }

        if (! in_array(self::HOD, $audiences, true) || ($binding = $this->activeBinding($user, $companyEntityId)) === null) {
            return [];
        }

        $departmentIds = WorkforceEmployeeProjection::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('active', true)
            ->where('department_head_entity_id', $binding->employee_entity_id)
            ->whereNotNull('organization_entity_id')
            ->distinct()
            ->pluck('organization_entity_id');

        return $units->whereIn('workforce_entity_id', $departmentIds)
            ->pluck('workforce_entity_id')->map(intval(...))->all();
    }

    /** @return list<string> */
    public function authorizeAudience(User $user, string $functionalCapability): array
    {
        $actor = Actor::forUser($user);
        $this->authorization->authorize($actor, $functionalCapability);

        $audiences = array_values(array_filter(
            array_keys(self::ROLE_CODES),
            fn (string $audience): bool => $this->hasAudience($actor, $audience),
        ));

        if ($audiences === []) {
            $this->deny();
        }

        return $audiences;
    }

    public function mayAccess(User $user, string $functionalCapability): bool
    {
        try {
            $this->authorizeAudience($user, $functionalCapability);

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    public function assertHr(User $user, int $companyEntityId): void
    {
        if (! $this->may($user, 'people-connector.skill.catalog.manage', $companyEntityId, [self::HR])) {
            $this->deny();
        }
    }

    public function mayGovernRequirements(User $user, int $companyEntityId): bool
    {
        return $this->may(
            $user,
            'people-connector.skill-requirement.approve',
            $companyEntityId,
            [self::HR],
        );
    }

    /** Requirement versions are visible only to their real governance audience. */
    public function mayViewRequirementProfile(User $user, RequirementProfile $profile): bool
    {
        return $this->mayGovernRequirements($user, (int) $profile->company_entity_id)
            || $this->mayReviewRequirementProfile($user, $profile);
    }

    /**
     * HOD review is valid only when every department or position selector is
     * inside a department currently headed by the actor. Company-wide
     * profiles deliberately remain an HR-only concern: one HOD cannot attest
     * technical correctness for sibling departments.
     */
    public function mayReviewRequirementProfile(User $user, RequirementProfile $profile): bool
    {
        try {
            $visibleUnits = $this->visibleOrganizationUnitEntityIds(
                $user,
                (int) $profile->company_entity_id,
                'people-connector.skill-requirement.hod-approve',
            );
        } catch (AuthorizationDeniedException) {
            return false;
        }

        if ($visibleUnits === []) {
            return false;
        }

        $selectors = RequirementProfileSelector::query()
            ->forCompany($this->tenantContext->requireTenantId(), (int) $profile->company_entity_id)
            ->where('profile_id', $profile->getKey())
            ->get();

        if ($selectors->isEmpty() || $selectors->contains(
            fn (RequirementProfileSelector $selector): bool => $selector->selector_type === SelectorType::Company,
        )) {
            return false;
        }

        $targetUnits = $selectors->map(function (RequirementProfileSelector $selector) use ($profile): ?int {
            if ($selector->selector_type === SelectorType::Department) {
                return $selector->selector_entity_id === null ? null : (int) $selector->selector_entity_id;
            }

            if ($selector->selector_type !== SelectorType::Position || $selector->selector_entity_id === null) {
                return null;
            }

            return WorkforcePositionProjection::query()
                ->forCompany($this->tenantContext->requireTenantId(), (int) $profile->company_entity_id)
                ->where('active', true)
                ->where('workforce_entity_id', $selector->selector_entity_id)
                ->value('organization_entity_id');
        });

        return ! $targetUnits->contains(null)
            && $targetUnits->every(fn (int $unitId): bool => in_array($unitId, $visibleUnits, true));
    }

    public function authorizeAssessmentSubmission(
        User $user,
        int $companyEntityId,
        int $employeeEntityId,
    ): void {
        if (! $this->mayForEmployee(
            $user,
            'people-connector.skill.assessment.submit',
            $companyEntityId,
            $employeeEntityId,
            [self::HR, self::HOD, self::ASSESSOR],
        )) {
            $this->deny();
        }
    }

    public function authorizeHodVerification(
        User $user,
        int $companyEntityId,
        int $employeeEntityId,
    ): void {
        if (! $this->mayForEmployee(
            $user,
            'people-connector.skill.assessment.verify',
            $companyEntityId,
            $employeeEntityId,
            [self::HOD],
        )) {
            $this->deny();
        }
    }

    public function authorizeAssessmentFinalization(
        User $user,
        int $companyEntityId,
        int $employeeEntityId,
    ): void {
        if (! $this->mayForEmployee(
            $user,
            'people-connector.skill.assessment.approve',
            $companyEntityId,
            $employeeEntityId,
            [self::HOD],
        )) {
            $this->deny();
        }
    }

    public function boundEmployeeEntityId(User $user, int $companyEntityId): ?int
    {
        return ($binding = $this->activeBinding($user, $companyEntityId)) === null
            ? null
            : (int) $binding->employee_entity_id;
    }

    /** @param list<string> $requiredAudiences */
    private function may(User $user, string $capability, int $companyEntityId, array $requiredAudiences): bool
    {
        try {
            $audiences = $this->authorizeAudience($user, $capability);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return $this->companies->mayActFor($user, $companyEntityId)
            && array_intersect($requiredAudiences, $audiences) !== [];
    }

    /** @param list<string> $requiredAudiences */
    private function mayForEmployee(
        User $user,
        string $capability,
        int $companyEntityId,
        int $employeeEntityId,
        array $requiredAudiences,
    ): bool {
        try {
            $audiences = $this->authorizeAudience($user, $capability);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return $this->companies->mayActFor($user, $companyEntityId)
            && array_intersect($requiredAudiences, $audiences) !== []
            && in_array(
                $employeeEntityId,
                $this->visibleEmployeeEntityIds($user, $companyEntityId, manage: true),
                true,
            );
    }

    private function hasAudience(Actor $actor, string $audience): bool
    {
        $capability = 'people-connector.skill.'.$audience.'.view';
        $decision = $this->authorization->can($actor, $capability);

        if (! $decision->allowed) {
            return false;
        }

        if (! in_array('grant_all', $decision->appliedPolicies, true)) {
            return true;
        }

        // EffectivePermissions short-circuits every role to grant_all when a
        // platform administrator also has one. Inspect only the named People
        // role so a legitimate dual-role user is not denied.
        return PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
            ->where('base_authz_principal_roles.principal_id', $actor->id)
            ->where(function ($query) use ($actor): void {
                $query->whereNull('base_authz_principal_roles.company_id')
                    ->orWhere('base_authz_principal_roles.company_id', $actor->companyId);
            })
            ->where('base_authz_roles.code', self::ROLE_CODES[$audience])
            ->exists();
    }

    private function activeBinding(User $user, int $companyEntityId): ?SkillActorBinding
    {
        $tenantId = $this->tenantContext->requireTenantId();

        return SkillActorBinding::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('platform_user_id', $user->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('people_connector_connector_workforce_employees as employee')
                    ->join('people_connector_connector_workforce_entities as employee_entity', function ($join): void {
                        $join->on('employee_entity.id', '=', 'employee.workforce_entity_id')
                            ->on('employee_entity.tenant_id', '=', 'employee.tenant_id');
                    })
                    ->join('people_connector_connector_workforce_entities as user_entity', function ($join): void {
                        $join->on('user_entity.id', '=', 'employee.user_entity_id')
                            ->on('user_entity.tenant_id', '=', 'employee.tenant_id');
                    })
                    ->whereColumn('employee.tenant_id', 'people_connector_skill_actor_bindings.tenant_id')
                    ->whereColumn('employee.company_entity_id', 'people_connector_skill_actor_bindings.company_entity_id')
                    ->whereColumn('employee.workforce_entity_id', 'people_connector_skill_actor_bindings.employee_entity_id')
                    ->whereColumn('employee.user_entity_id', 'people_connector_skill_actor_bindings.user_entity_id')
                    ->where('employee.active', true)
                    ->where('employee_entity.state', WorkforceEntity::STATE_ACTIVE)
                    ->where('user_entity.state', WorkforceEntity::STATE_ACTIVE);
            })
            ->first();
    }

    private function deny(): never
    {
        throw new AuthorizationDeniedException(AuthorizationDecision::deny(
            AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
            ['people_connector_skill_audience'],
        ));
    }
}
