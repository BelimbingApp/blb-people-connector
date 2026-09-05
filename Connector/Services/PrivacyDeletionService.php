<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\PrivacyDeletionReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceHistoryEvent;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\PrivacyDeletionException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Company-scoped privacy erasure for connector-owned workforce projections.
 *
 * Implements the first deletion slice for blb-people#24 / connector#54:
 * tombstone Class C personal projection fields, redact snapshot payloads,
 * leave identity tokens and append-only support/provenance evidence intact.
 */
final class PrivacyDeletionService
{
    private const REDACTED_LABEL = '[redacted]';

    public const ERASE_CAPABILITY = 'people-connector.identity.erase';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly WorkforceHistory $history,
    ) {}

    /**
     * Erase one provider identity without erasing People history.
     *
     * The workforce entity id survives: People-owned business records point at
     * it, and this method has no business changing what they see. What goes is
     * the personal detail the connector holds — the projection fields and the
     * snapshot payloads — plus the identity's right to keep syncing. An audit
     * row naming the erased reference is appended, never overwritten.
     *
     * An identity a reconciliation issue is still open against is refused.
     * Erasing it would leave an operator holding a queue entry about a person
     * whose evidence has just been redacted underneath them, with no way to
     * finish the decision the issue is asking for.
     */
    public function eraseIdentity(
        Actor $actor,
        int $connectionId,
        ExternalReference $reference,
        WorkforceProvenance $provenance,
        ?\DateTimeInterface $erasedAt = null,
    ): WorkforceEntity {
        $tenantId = $this->tenantContext->requireTenantId();
        $erasedAt ??= now();

        $connection = ProviderConnection::query()
            ->forTenant($tenantId)
            ->whereKey($connectionId)
            ->first();

        if ($connection === null) {
            throw new PrivacyDeletionException(
                "Privacy deletion requires a connection in the current tenant; [{$connectionId}] is not one.",
            );
        }

        // A company workforce entity is the scope personal data hangs off, not
        // personal data itself: its projection has no privacy_deleted_at column
        // and never did. Erasing a whole company is eraseCompany's job, which
        // tombstones the company's members and leaves the company row standing.
        if ($reference->resourceType === WorkforceResourceType::Company) {
            throw new PrivacyDeletionException(
                'Identity erasure covers a person, not a company; use eraseCompany to erase a company scope.',
            );
        }

        $companyId = $connection->company_id === null ? null : (int) $connection->company_id;

        $this->authorization->authorize($actor, self::ERASE_CAPABILITY, new ResourceContext(
            type: 'people-connector.identity',
            id: $reference->externalId,
            companyId: $companyId,
            tenantId: $tenantId,
        ));

        $this->assertActor($actor, $tenantId, $companyId);

        return DB::transaction(function () use ($connection, $tenantId, $connectionId, $reference, $provenance, $erasedAt): WorkforceEntity {
            $identity = ExternalIdentity::query()
                ->forTenant($tenantId)
                ->where('connection_id', $connectionId)
                ->where('provider_id', $reference->providerId)
                ->where('resource_type', $reference->resourceType->value)
                ->where('external_id', $reference->externalId)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                throw new PrivacyDeletionException(
                    "Privacy deletion requires a known provider identity; [{$reference->externalId}] is not one on connection [{$connectionId}].",
                );
            }

            $this->assertNoOpenReconciliationIssue($tenantId, $connectionId, $reference);

            $entity = WorkforceEntity::query()
                ->forTenant($tenantId)
                ->whereKey((int) $identity->workforce_entity_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->tombstoneProjections((int) $entity->id, $tenantId, $erasedAt);
            $this->redactSnapshots($tenantId, (int) $entity->id, $erasedAt);

            $identity->forceFill([
                'state' => ExternalIdentity::STATE_INACTIVE,
                'effective_to' => $erasedAt,
            ])->save();

            $this->history->record(
                $connection,
                $entity,
                $identity,
                WorkforceHistoryEvent::identityErased($reference),
                $erasedAt,
                $erasedAt,
                $provenance,
            );

            return $entity;
        });
    }

    public function eraseCompany(int $companyEntityId, ?\DateTimeInterface $erasedAt = null): PrivacyDeletionReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $erasedAt ??= now();

        $company = WorkforceEntity::query()
            ->forTenant($tenantId)
            ->whereKey($companyEntityId)
            ->first();

        if ($company === null || $company->resource_type !== WorkforceResourceType::Company->value) {
            throw new PrivacyDeletionException(
                "Privacy deletion requires an existing company workforce entity; [{$companyEntityId}] is not one.",
            );
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $erasedAt): PrivacyDeletionReport {
            $employees = WorkforceEmployeeProjection::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereNull('privacy_deleted_at')
                ->get();

            $orgUnits = WorkforceOrganizationUnitProjection::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereNull('privacy_deleted_at')
                ->get();

            $positions = WorkforcePositionProjection::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereNull('privacy_deleted_at')
                ->get();

            $entityIds = $employees->pluck('workforce_entity_id')
                ->merge($orgUnits->pluck('workforce_entity_id'))
                ->merge($positions->pluck('workforce_entity_id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            foreach ($employees as $employee) {
                $employee->forceFill([
                    'display_name' => self::REDACTED_LABEL,
                    'employee_number' => null,
                    'email' => null,
                    'active' => false,
                    'privacy_deleted_at' => $erasedAt,
                ])->save();
            }

            foreach ($orgUnits as $orgUnit) {
                $orgUnit->forceFill([
                    'name' => self::REDACTED_LABEL,
                    'code' => null,
                    'active' => false,
                    'privacy_deleted_at' => $erasedAt,
                ])->save();
            }

            foreach ($positions as $position) {
                $position->forceFill([
                    'name' => self::REDACTED_LABEL,
                    'code' => null,
                    'tier' => null,
                    'active' => false,
                    'privacy_deleted_at' => $erasedAt,
                ])->save();
            }

            $snapshotsRedacted = 0;
            if ($entityIds !== []) {
                $snapshots = WorkforceSnapshot::query()
                    ->forTenant($tenantId)
                    ->whereIn('workforce_entity_id', $entityIds)
                    ->whereNull('redacted_at')
                    ->get();

                foreach ($snapshots as $snapshot) {
                    $snapshot->redact($erasedAt);
                    $snapshotsRedacted++;
                }
            }

            return new PrivacyDeletionReport(
                companyEntityId: $companyEntityId,
                employeesTombstoned: $employees->count(),
                organizationUnitsTombstoned: $orgUnits->count(),
                positionsTombstoned: $positions->count(),
                snapshotsRedacted: $snapshotsRedacted,
            );
        });
    }

    private function assertActor(Actor $actor, int $tenantId, ?int $companyId): void
    {
        if ($actor->validate() !== null
            || $actor->tenantId !== $tenantId
            || ($companyId !== null && $actor->companyId !== $companyId)) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'erase_identity',
                message: 'Privacy erasure requires an actor inside the connection tenant and company boundary.',
            );
        }
    }

    private function assertNoOpenReconciliationIssue(int $tenantId, int $connectionId, ExternalReference $reference): void
    {
        $open = ReconciliationIssue::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->where('resource_type', $reference->resourceType->value)
            ->where('external_id', $reference->externalId)
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->exists();

        if ($open) {
            throw new PrivacyDeletionException(
                "Privacy deletion is refused while an open reconciliation issue still references [{$reference->externalId}].",
            );
        }
    }

    private function tombstoneProjections(int $entityId, int $tenantId, \DateTimeInterface $erasedAt): void
    {
        $employee = WorkforceEmployeeProjection::query()
            ->forTenant($tenantId)
            ->withoutCompanyScope('Addresses one projection by the canonical workforce entity id, which is unique per tenant; the caller resolved that entity through the connection it is already authorized for.')
            ->where('workforce_entity_id', $entityId)
            ->whereNull('privacy_deleted_at')
            ->first();
        $employee?->forceFill([
            'display_name' => self::REDACTED_LABEL,
            'employee_number' => null,
            'email' => null,
            'active' => false,
            'privacy_deleted_at' => $erasedAt,
        ])->save();

        $orgUnit = WorkforceOrganizationUnitProjection::query()
            ->forTenant($tenantId)
            ->withoutCompanyScope('Addresses one projection by the canonical workforce entity id, which is unique per tenant; the caller resolved that entity through the connection it is already authorized for.')
            ->where('workforce_entity_id', $entityId)
            ->whereNull('privacy_deleted_at')
            ->first();
        $orgUnit?->forceFill([
            'name' => self::REDACTED_LABEL,
            'code' => null,
            'active' => false,
            'privacy_deleted_at' => $erasedAt,
        ])->save();

        $position = WorkforcePositionProjection::query()
            ->forTenant($tenantId)
            ->withoutCompanyScope('Addresses one projection by the canonical workforce entity id, which is unique per tenant; the caller resolved that entity through the connection it is already authorized for.')
            ->where('workforce_entity_id', $entityId)
            ->whereNull('privacy_deleted_at')
            ->first();
        $position?->forceFill([
            'name' => self::REDACTED_LABEL,
            'code' => null,
            'tier' => null,
            'active' => false,
            'privacy_deleted_at' => $erasedAt,
        ])->save();
    }

    private function redactSnapshots(int $tenantId, int $entityId, \DateTimeInterface $erasedAt): void
    {
        $snapshots = WorkforceSnapshot::query()
            ->forTenant($tenantId)
            ->where('workforce_entity_id', $entityId)
            ->whereNull('redacted_at')
            ->get();

        foreach ($snapshots as $snapshot) {
            $snapshot->redact($erasedAt);
        }
    }
}
