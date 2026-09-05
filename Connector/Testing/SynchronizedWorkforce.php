<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;

/**
 * One organisation unit, position and employee synchronized into a workforce
 * company, built the way an adapter builds them: entity, identity, projection.
 *
 * The sibling of CompanyIsolationContract::synchronizedCompany(), which does
 * the same for the company axis itself. Projections carry a real
 * source_identity_id because the schema's foreign key means a placeholder is
 * not a shortcut, it is a failed insert.
 */
final class SynchronizedWorkforce
{
    public const PROVIDER_ID = 'test.people';

    /**
     * @return array<string, int> resource type value => workforce entity id
     */
    public static function inCompany(int $tenantId, int $companyEntityId, bool $active = true): array
    {
        $connection = ProviderConnection::query()->firstOrCreate([
            'tenant_id' => $tenantId,
            'scope_key' => 'tenant',
            'provider_id' => self::PROVIDER_ID,
        ], ['company_id' => null, 'status' => ProviderConnection::STATUS_ACTIVE]);

        $rows = [];

        foreach ([
            WorkforceResourceType::OrganizationUnit->value => [WorkforceOrganizationUnitProjection::class, ['name' => 'Operations']],
            WorkforceResourceType::Position->value => [WorkforcePositionProjection::class, ['name' => 'Operator']],
            WorkforceResourceType::Employee->value => [WorkforceEmployeeProjection::class, ['display_name' => 'Projected Worker']],
        ] as $type => [$model, $attributes]) {
            $entity = WorkforceEntity::query()->create([
                'tenant_id' => $tenantId,
                'resource_type' => $type,
                'state' => WorkforceEntity::STATE_ACTIVE,
                'first_seen_at' => now(),
            ]);
            $externalId = $type.'-'.$entity->id;
            $identity = ExternalIdentity::query()->create([
                'tenant_id' => $tenantId,
                'connection_id' => $connection->id,
                'workforce_entity_id' => $entity->id,
                'provider_id' => self::PROVIDER_ID,
                'resource_type' => $type,
                'external_id' => $externalId,
                'external_id_hash' => hash('sha256', $externalId),
                'state' => ExternalIdentity::STATE_ACTIVE,
                'effective_from' => now(),
                'last_observed_at' => now(),
            ]);

            $model::query()->create($attributes + [
                'tenant_id' => $tenantId,
                'workforce_entity_id' => $entity->id,
                'company_entity_id' => $companyEntityId,
                'source_identity_id' => $identity->id,
                'active' => $active,
                'effective_at' => now(),
                'observed_at' => now(),
            ]);

            $rows[$type] = (int) $entity->id;
        }

        return $rows;
    }
}
