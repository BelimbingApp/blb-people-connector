<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceHistoryEvent;
use App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceProjectionConflictException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use Illuminate\Support\Facades\DB;

final class WorkforceProjectionStore
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
        private WorkforceIdentityStore $identities,
        private WorkforceHistory $history,
    ) {}

    public function upsert(
        int $connectionId,
        WorkforceCompany|WorkforceOrganizationUnit|WorkforcePosition|WorkforceEmployee $record,
        ?WorkforceProvenance $provenance = null,
    ): WorkforceCompanyProjection|WorkforceOrganizationUnitProjection|WorkforcePositionProjection|WorkforceEmployeeProjection {
        return DB::transaction(function () use ($connectionId, $record, $provenance): WorkforceCompanyProjection|WorkforceOrganizationUnitProjection|WorkforcePositionProjection|WorkforceEmployeeProjection {
            $connection = $this->connections->get($connectionId, lock: true);
            $observedAt = $record->observedAt;
            $sourceVersion = $record->sourceVersion;
            $identity = $this->identities->resolveOrCreateIdentity(
                $connectionId,
                $record->reference,
                $observedAt,
                provenance: $provenance,
                sourceVersion: $sourceVersion,
            );
            $this->identities->assertActive($identity);
            $entity = $this->identities->resolve($connectionId, $record->reference);
            $effectiveAt = $record instanceof WorkforceCompany ? $record->observedAt : $record->effectiveAt;
            $this->history->record(
                $connection,
                $entity,
                $identity,
                WorkforceHistoryEvent::projectionUpserted($record),
                $effectiveAt,
                $observedAt,
                $provenance,
                $sourceVersion,
            );

            $base = [
                'tenant_id' => $this->tenantContext->requireTenantId(),
                'workforce_entity_id' => $entity->id,
                'source_identity_id' => $identity->id,
                'active' => $record->active,
                'effective_at' => $effectiveAt,
                'observed_at' => $observedAt,
                'source_version' => $sourceVersion,
            ];

            $projection = match (true) {
                $record instanceof WorkforceCompany => $this->persistCurrent(
                    WorkforceCompanyProjection::class,
                    $base + [
                        'name' => $record->name,
                        'code' => $record->code,
                    ],
                    $observedAt,
                ),
                $record instanceof WorkforceOrganizationUnit => $this->persistCurrent(
                    WorkforceOrganizationUnitProjection::class,
                    $base + [
                        'company_entity_id' => $this->relatedEntityId($connection, $record->companyReference, $observedAt, $provenance),
                        'parent_entity_id' => $this->relatedEntityId($connection, $record->parentReference, $observedAt, $provenance),
                        'name' => $record->name,
                        'code' => $record->code,
                        'kind' => $record->kind,
                    ],
                    $observedAt,
                ),
                $record instanceof WorkforcePosition => $this->persistCurrent(
                    WorkforcePositionProjection::class,
                    $base + [
                        'company_entity_id' => $this->relatedEntityId($connection, $record->companyReference, $observedAt, $provenance),
                        'organization_entity_id' => $this->relatedEntityId($connection, $record->organizationReference, $observedAt, $provenance),
                        'name' => $record->name,
                        'code' => $record->code,
                        'tier' => $record->tier,
                    ],
                    $observedAt,
                ),
                default => $this->persistEmployee($connection, $record, $base, $observedAt, $provenance),
            };

            if (! $projection->observed_at->greaterThan($observedAt)) {
                $entity->fill([
                    'state' => $record->active ? WorkforceEntity::STATE_ACTIVE : WorkforceEntity::STATE_INACTIVE,
                    'deactivated_at' => $record->active ? null : $effectiveAt,
                ])->save();
            }

            return $projection;
        });
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function persistEmployee(
        ProviderConnection $connection,
        WorkforceEmployee $record,
        array $base,
        \DateTimeInterface $observedAt,
        ?WorkforceProvenance $provenance,
    ): WorkforceEmployeeProjection {
        return $this->persistCurrent(
            WorkforceEmployeeProjection::class,
            $base + [
                'company_entity_id' => $this->relatedEntityId($connection, $record->companyReference, $observedAt, $provenance),
                'user_entity_id' => $this->relatedEntityId($connection, $record->userReference, $observedAt, $provenance),
                'organization_entity_id' => $this->relatedEntityId($connection, $record->organizationReference, $observedAt, $provenance),
                'position_entity_id' => $this->relatedEntityId($connection, $record->positionReference, $observedAt, $provenance),
                'manager_entity_id' => $this->relatedEntityId($connection, $record->managerReference, $observedAt, $provenance),
                'department_head_entity_id' => $this->relatedEntityId($connection, $record->departmentHeadReference, $observedAt, $provenance),
                'display_name' => $record->displayName,
                'employee_number' => $record->employeeNumber,
                'email' => $record->email,
            ],
            $observedAt,
            // The adapter's identity resolution can legitimately fail to
            // confirm a user this pass — e.g. blb-people#25's HR-governed
            // portal-access confirmation not (yet) active — without that
            // meaning the platform user link is gone. Rule 9.1 requires a
            // positive statement before a projection is torn down; "the
            // adapter didn't reconfirm it this pass" is not one. Only an
            // explicit non-null value, or an explicit revocation signal,
            // may overwrite an existing link — otherwise it is left alone.
            stickyOnNull: $record->userReferenceRevoked ? [] : ['user_entity_id'],
        );
    }

    /**
     * @template TProjection of TenantOwnedModel
     *
     * @param  class-string<TProjection>  $model
     * @param  array<string, mixed>  $values
     * @param  list<string>  $stickyOnNull  attributes that must never be overwritten with
     *                                      null while an existing projection already holds
     *                                      a non-null value — see rule 9.1: the adapter
     *                                      failing to reconfirm a fact is not a positive
     *                                      statement that the fact is gone.
     * @return TProjection
     */
    private function persistCurrent(string $model, array $values, \DateTimeInterface $observedAt, array $stickyOnNull = []): TenantOwnedModel
    {
        $current = $model::query()
            ->withoutCompanyScope('A sync pass addresses exactly one projection by the canonical workforce entity id it just resolved through this connection, and the company is part of the payload being written.')
            ->forTenant($this->tenantContext->requireTenantId())
            ->where('workforce_entity_id', $values['workforce_entity_id'])
            ->lockForUpdate()
            ->first();

        if ($current !== null) {
            foreach ($stickyOnNull as $attribute) {
                if (($values[$attribute] ?? null) === null && $current->getAttribute($attribute) !== null) {
                    unset($values[$attribute]);
                }
            }
        }

        if ($current !== null && $current->observed_at->greaterThan($observedAt)) {
            return $current;
        }

        if ($current !== null && $current->observed_at->equalTo($observedAt)) {
            foreach ($values as $attribute => $value) {
                if (! $this->valuesMatch($current->getAttribute($attribute), $value)) {
                    throw new WorkforceProjectionConflictException(
                        'Conflicting workforce facts share the same provider observation time.',
                    );
                }
            }

            return $current;
        }

        if ($current === null) {
            return $model::query()->create($values);
        }

        $current
            ->movingCompany('A sync pass writes the provider payload as observed, and the company an employee, position or unit belongs to is part of that payload; a transfer between companies arrives as exactly this update.')
            ->fill($values)
            ->save();

        return $current->refresh();
    }

    private function valuesMatch(mixed $current, mixed $incoming): bool
    {
        if ($current instanceof \DateTimeInterface && $incoming instanceof \DateTimeInterface) {
            return $current->getTimestamp() === $incoming->getTimestamp();
        }

        if ($current === null || $incoming === null) {
            return $current === $incoming;
        }

        if (is_bool($current) || is_bool($incoming)) {
            return (bool) $current === (bool) $incoming;
        }

        return (string) $current === (string) $incoming;
    }

    private function relatedEntityId(
        ProviderConnection $connection,
        ?ExternalReference $reference,
        \DateTimeInterface $observedAt,
        ?WorkforceProvenance $provenance,
    ): ?int {
        if ($reference === null) {
            return null;
        }

        $this->identities->resolveOrCreateIdentity(
            (int) $connection->id,
            $reference,
            $observedAt,
            provenance: $provenance,
        );

        return (int) $this->identities->resolve((int) $connection->id, $reference)->id;
    }
}
