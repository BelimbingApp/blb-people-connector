<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Data\WorkforceSubjectResolution;
use App\Domains\People\Provider\Enums\WorkforceResourceType as SubjectResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use Illuminate\Database\Eloquent\Model;

/**
 * The People subject seam, answered from synchronized projections.
 *
 * A remote People install has no Core company or employee rows to read, so the
 * same question — "which record is this stable workforce identity?" — has to be
 * answered from what the connector synchronized. The refusal vocabulary is the
 * native resolver's, deliberately: a caller must not be able to tell which side
 * answered from the shape of a denial. WorkforceSubjectDenialParityTest holds
 * the two implementations to that.
 *
 * The connector's stable identity is the workforce entity id, so `stableId` is
 * an entity id here and `companyId` is the owning *workforce* company entity
 * id, not a platform company id. Both sides still refuse on the same axis; only
 * the id space differs, because only the id space can differ.
 */
final class ProjectionWorkforceSubjectResolver implements ResolvesWorkforceSubjects
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function resolve(WorkforceSubject $subject): WorkforceSubjectResolution
    {
        $currentTenantId = $this->tenantContext->currentTenantId();

        // $currentTenantId === null is kept for the type narrowing find()
        // needs, not for the decision: a null ambient tenant is already
        // refused by the mismatch below, exactly as in the native resolver.
        // A subject tenant of null is refused there too, so it has no clause
        // of its own.
        if ($currentTenantId === null
            || $subject->companyId === null
            || $subject->tenantId !== $currentTenantId
            || ! ctype_digit($subject->stableId)) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        $entityId = (int) $subject->stableId;
        $entity = WorkforceEntity::query()
            ->forTenant($currentTenantId)
            ->whereKey($entityId)
            ->first();

        // A type mismatch is Unknown rather than a lookup against the wrong
        // table: the entity row is what says what this identity *is*, and a
        // caller asking for a position by an employee's id has named nothing.
        if ($entity === null || $entity->getAttribute('resource_type') !== $subject->type->value) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        if (! $this->providerOwnsEntity($subject, $currentTenantId, $entityId)) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        $projection = $this->projection($subject->type, $currentTenantId, $entityId);

        if ($projection === null) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        // A company projection *is* its own company axis; every other
        // projection carries the owning company entity beside it.
        $owningCompanyEntityId = $subject->type === SubjectResourceType::Company
            ? (int) $projection->getAttribute('workforce_entity_id')
            : (int) $projection->getAttribute('company_entity_id');

        if ($owningCompanyEntityId !== $subject->companyId) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::WrongCompany);
        }

        // Two independent retirements. The entity is retired when the identity
        // stops existing; the projection is inactive when the provider still
        // publishes the record but says it is not current. Either one means the
        // subject must not resolve.
        if ($entity->getAttribute('state') !== WorkforceEntity::STATE_ACTIVE
            || $projection->getAttribute('active') !== true) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Deactivated);
        }

        return WorkforceSubjectResolution::resolved($projection);
    }

    /**
     * A subject may name the provider it came from. When it does, the entity
     * must actually carry an identity minted by that provider, so a reference
     * belonging to another HR system cannot be answered with this one's data.
     */
    private function providerOwnsEntity(WorkforceSubject $subject, int $tenantId, int $entityId): bool
    {
        if ($subject->externalReference === null) {
            return true;
        }

        return ExternalIdentity::query()
            ->forTenant($tenantId)
            ->where('workforce_entity_id', $entityId)
            ->where('provider_id', $subject->externalReference->providerId)
            ->exists();
    }

    /**
     * The company axis is what this resolver is deciding, so it cannot be a
     * query filter: pinning the company here would turn every sibling-company
     * subject into Unknown and lose the WrongCompany answer the seam owes its
     * caller. The row is loaded across companies inside one tenant and the
     * comparison is made above, in the open.
     */
    private function projection(SubjectResourceType $type, int $tenantId, int $entityId): ?Model
    {
        $model = match ($type) {
            SubjectResourceType::Company => WorkforceCompanyProjection::class,
            SubjectResourceType::OrganizationUnit => WorkforceOrganizationUnitProjection::class,
            SubjectResourceType::Position => WorkforcePositionProjection::class,
            SubjectResourceType::Employee => WorkforceEmployeeProjection::class,
            SubjectResourceType::User => null,
        };

        if ($model === null) {
            return null;
        }

        return $model::query()
            ->withoutCompanyScope('the seam answers WrongCompany, so it must read the row before comparing its company')
            ->forTenant($tenantId)
            ->where('workforce_entity_id', $entityId)
            ->first();
    }
}
