<?php

namespace App\Domains\PeopleConnector\Training\Services;

use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Training\Models\TrainingEvent;
use Illuminate\Database\Eloquent\Builder;

/** Deep HR/company and HOD/department boundary for training records. */
final class TrainingAudience
{
    public const VIEW = 'people-connector.training.event.view';

    public const MANAGE = 'people-connector.training.event.manage';

    public const REQUEST_SUBMIT = 'people-connector.training.request.submit';
    public const REQUEST_HOD_REVIEW = 'people-connector.training.request.hod-review';
    public const REQUEST_HR_REVIEW = 'people-connector.training.request.hr-review';
    public const REQUEST_APPROVE = 'people-connector.training.request.approve';

    public function __construct(
        private readonly SkillAudience $skills,
        private readonly CompanyAttribution $companies,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @return array<int, string> */
    public function allowedCompanies(User $user): array
    {
        return $this->skills->allowedCompanies($user, self::VIEW);
    }

    public function canManage(User $user, int $companyEntityId): bool
    {
        try {
            $audiences = $this->skills->authorizeAudience($user, self::MANAGE);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return in_array(SkillAudience::HR, $audiences, true)
            && $this->companies->mayActFor($user, $companyEntityId);
    }

    public function authorizeManage(User $user, int $companyEntityId): void
    {
        if (! $this->canManage($user, $companyEntityId)) {
            $this->deny();
        }
    }

    public function authorizeRequest(User $user, string $capability, int $companyEntityId, string $audience): void
    {
        try { $audiences = $this->skills->authorizeAudience($user, $capability); } catch (AuthorizationDeniedException) { $this->deny(); }
        if (! $this->companies->mayActFor($user, $companyEntityId) || ! in_array($audience, $audiences, true)) $this->deny();
    }

    public function visibleEvents(User $user, int $companyEntityId): Builder
    {
        $audiences = $this->skills->authorizeAudience($user, self::VIEW);
        if (! $this->companies->mayActFor($user, $companyEntityId)) {
            $this->deny();
        }

        $query = TrainingEvent::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId);

        if (in_array(SkillAudience::HR, $audiences, true)) {
            return $query;
        }

        if (in_array(SkillAudience::HOD, $audiences, true)) {
            $departments = $this->skills->visibleOrganizationUnitEntityIds($user, $companyEntityId, self::VIEW);

            // A NULL target is deliberately company-wide, so every attributed
            // HOD in the company sees it alongside events for departments they head.
            if ($departments === []) {
                return $query->whereNull('target_department_entity_id');
            }

            $parameters = implode(', ', array_fill(0, count($departments), '?'));

            return $query->whereRaw(
                "(target_department_entity_id is null or target_department_entity_id in ($parameters))",
                $departments,
            );
        }

        $this->deny();
    }

    private function deny(): never
    {
        throw new AuthorizationDeniedException(AuthorizationDecision::deny(
            AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
            ['people_connector_training_audience'],
        ));
    }
}
