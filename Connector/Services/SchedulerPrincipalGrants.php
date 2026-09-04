<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Illuminate\Support\Facades\DB;

/**
 * Directory-read grants for the per-connection SCHEDULER principal (#78/#70).
 *
 * Inserted on connection activation; removed on deactivation. The grant list
 * is the directory-read set the [1006] sync runner reaches through
 * ProviderPortResolver — not write, payroll, or documents.
 */
final class SchedulerPrincipalGrants
{
    /** @return list<PeopleCapability> */
    public static function directoryReadCapabilities(): array
    {
        return [
            PeopleCapability::EmployeeDirectory,
            PeopleCapability::CompanyDirectory,
            PeopleCapability::OrganizationDirectory,
            PeopleCapability::UserDirectory,
            PeopleCapability::ManagerHierarchy,
        ];
    }

    public function grant(ProviderConnection $connection): void
    {
        $companyId = $connection->company_id === null ? null : (int) $connection->company_id;
        $principalId = (int) $connection->id;

        DB::transaction(function () use ($connection, $companyId, $principalId): void {
            foreach (self::directoryReadCapabilities() as $capability) {
                $key = ProviderPortAuthorization::permissionFor($capability, 'read');
                PrincipalCapability::query()->updateOrCreate(
                    [
                        'principal_type' => PrincipalType::SCHEDULER->value,
                        'principal_id' => $principalId,
                        'capability_key' => $key,
                        'company_id' => $companyId,
                    ],
                    ['is_allowed' => true],
                );
            }
        });
    }

    public function revoke(ProviderConnection $connection): void
    {
        PrincipalCapability::query()
            ->where('principal_type', PrincipalType::SCHEDULER->value)
            ->where('principal_id', (int) $connection->id)
            ->delete();
    }
}
