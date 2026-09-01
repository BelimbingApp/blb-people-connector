<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The company-isolation contract, in a form every slice inherits rather than
 * copies.
 *
 * `companyOwnedModels()` finds every model in the domain that declares itself
 * company-owned, so a new slice is enrolled by adding the trait, not by
 * remembering to write a test. `violations()` then states what being
 * company-owned must mean, following the same shape as ProviderConformance.
 *
 * `twoCompaniesInOneTenant()` builds the fixture the repository never had: two
 * companies on the same side of the tenant boundary, which is the only
 * arrangement in which a company leak is visible at all.
 */
final class CompanyIsolationContract
{
    /**
     * A tenant id no fixture uses, so a broken guard cannot touch real rows
     * while we check that it refuses to run.
     */
    private const UNUSED_TENANT_ID = 999_999_999;

    /**
     * Every model in this domain that declares itself company-owned.
     *
     * @return list<class-string<Model>>
     */
    public static function companyOwnedModels(): array
    {
        $models = [];

        foreach (self::modelFiles() as $file) {
            $class = self::classIn($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, Model::class) && in_array(CompanyOwned::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    /**
     * What goes wrong if this model's company boundary is not real. An empty
     * list is the contract being kept.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    public static function violations(string $model): array
    {
        $violations = [];
        $instance = new $model;
        /** @var list<string> $columns */
        $columns = $instance->companyScopeColumns();

        if ($columns === []) {
            return ['declares_no_company_scope_columns'];
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($instance->getTable(), $column)) {
                $violations[] = "declared_column_missing_from_table:$column";
            }
        }

        // The mistake all three lanes made: scope the tenant, stop there.
        $violations = array_merge($violations, self::refusals($model, [
            'tenant_scoped_read_allowed' => fn () => $model::query()->forTenant(self::UNUSED_TENANT_ID)->get(),
            'unscoped_read_allowed' => fn () => $model::query()->get(),
            'tenant_scoped_update_allowed' => fn () => $model::query()
                ->forTenant(self::UNUSED_TENANT_ID)
                ->update(['tenant_id' => self::UNUSED_TENANT_ID]),
            'tenant_scoped_delete_allowed' => fn () => $model::query()->forTenant(self::UNUSED_TENANT_ID)->delete(),
        ]));

        if ($instance->companyOwnerColumn() !== null) {
            try {
                $model::query()->forCompany(self::UNUSED_TENANT_ID, 1)->get();
            } catch (MissingCompanyScopeException) {
                $violations[] = 'for_company_does_not_satisfy_the_guard';
            }
        }

        try {
            $model::query()
                ->withoutCompanyScope('Contract check: the escape hatch must actually open.')
                ->forTenant(self::UNUSED_TENANT_ID)
                ->get();
        } catch (MissingCompanyScopeException) {
            $violations[] = 'escape_hatch_does_not_open';
        }

        try {
            $model::query()->withoutCompanyScope('   ')->get();
            $violations[] = 'escape_hatch_accepts_an_empty_reason';
        } catch (\InvalidArgumentException) {
            // Expected: an unexplained exception is not reviewable.
        } catch (MissingCompanyScopeException) {
            $violations[] = 'escape_hatch_accepts_an_empty_reason';
        }

        return $violations;
    }

    /**
     * Two companies inside one tenant, each with the platform company a user
     * belongs to and the synchronized workforce company that owns rows —
     * provisioned the way an adapter will: entity, identity, projection.
     */
    public static function twoCompaniesInOneTenant(
        string $alphaName = 'Alpha Industries',
        string $betaName = 'Beta Works',
    ): TwoCompanyTenant {
        $tenant = Tenant::query()->create(['name' => 'Two Company Tenant', 'status' => 'active']);
        $tenantId = (int) $tenant->id;

        $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $alphaName]);
        $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $betaName]);

        return new TwoCompanyTenant(
            tenantId: $tenantId,
            alphaCompany: $alpha,
            alphaCompanyEntityId: self::synchronizedCompany($tenantId, (int) $alpha->id, $alphaName),
            betaCompany: $beta,
            betaCompanyEntityId: self::synchronizedCompany($tenantId, (int) $beta->id, $betaName),
        );
    }

    /**
     * Provision one synchronized workforce company. A null platform company id
     * builds it behind a tenant-scoped connection, which is the case the
     * attribution rule cannot resolve and therefore fails closed on.
     *
     * @return int the workforce company entity id
     */
    public static function synchronizedCompany(int $tenantId, ?int $platformCompanyId, string $name): int
    {
        $entity = WorkforceEntity::query()->create([
            'tenant_id' => $tenantId,
            'resource_type' => WorkforceResourceType::Company->value,
            'state' => WorkforceEntity::STATE_ACTIVE,
            'first_seen_at' => now(),
        ]);

        $connection = ProviderConnection::query()->firstOrCreate([
            'tenant_id' => $tenantId,
            'scope_key' => $platformCompanyId === null ? 'tenant' : 'company:'.$platformCompanyId,
            'provider_id' => 'test.people',
        ], ['company_id' => $platformCompanyId, 'status' => ProviderConnection::STATUS_ACTIVE]);

        $externalId = 'company-'.$entity->id;
        $identity = ExternalIdentity::query()->create([
            'tenant_id' => $tenantId,
            'connection_id' => $connection->id,
            'workforce_entity_id' => $entity->id,
            'provider_id' => 'test.people',
            'resource_type' => WorkforceResourceType::Company->value,
            'external_id' => $externalId,
            'external_id_hash' => hash('sha256', $externalId),
            'state' => ExternalIdentity::STATE_ACTIVE,
            'effective_from' => now(),
            'last_observed_at' => now(),
        ]);

        WorkforceCompanyProjection::query()->create([
            'tenant_id' => $tenantId,
            'workforce_entity_id' => $entity->id,
            'source_identity_id' => $identity->id,
            'name' => $name,
            'active' => true,
            'effective_at' => now(),
            'observed_at' => now(),
        ]);

        return (int) $entity->id;
    }

    /**
     * @param  array<string, callable(): mixed>  $mustRefuse
     * @return list<string>
     */
    private static function refusals(string $model, array $mustRefuse): array
    {
        $violations = [];

        foreach ($mustRefuse as $violation => $query) {
            try {
                $query();
                $violations[] = $violation;
            } catch (MissingCompanyScopeException) {
                // Expected: the guard did its job.
            }
        }

        return $violations;
    }

    /** @return list<\SplFileInfo> */
    private static function modelFiles(): array
    {
        $domainRoot = dirname(__DIR__, 2);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domainRoot)) as $file) {
            if ($file->isFile()
                && $file->getExtension() === 'php'
                && str_contains(str_replace('\\', '/', $file->getPath()), '/Models')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return class-string|null */
    private static function classIn(\SplFileInfo $file): ?string
    {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($namespace[1]).'\\'.$class[1];

        return $fqcn;
    }
}
