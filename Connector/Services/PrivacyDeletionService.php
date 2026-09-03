<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\PrivacyDeletionReport;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\PrivacyDeletionException;
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

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

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
}
