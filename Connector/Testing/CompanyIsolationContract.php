<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /** @var list<class-string<Model>>|null */
    private static ?array $discovered = null;

    /**
     * Every model in this domain that declares itself company-owned.
     *
     * Resolved once per process, so the dataset a test is parameterized with
     * and the list the test body sees can never disagree.
     *
     * @return list<class-string<Model>>
     */
    public static function companyOwnedModels(): array
    {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

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

        return self::$discovered = array_values(array_unique($models));
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

        $violations = array_merge($violations, self::companyColumnViolations($model, $columns));

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
     * A correctly pinned write must still not be able to move the row to a
     * sibling company without saying so (blb-people-connector#18). The pin is
     * the exact shape the scope guard accepts, so this is the one write it
     * cannot refuse; the base query builder refuses the *value* instead.
     *
     * Every route below is public Eloquent API that ends in the base
     * builder's update() or upsert(). The review of #28 found five of them
     * walking past a check on the Eloquent builder; they are enumerated here
     * so the next one cannot.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function companyColumnViolations(string $model, array $columns): array
    {
        $violations = [];
        $column = $columns[0];
        $pinned = fn (): Builder => $model::query()->where($column, 1)->forTenant(self::UNUSED_TENANT_ID);

        $routes = [
            'update' => fn () => $pinned()->update([$column => 2]),
            'update_qualified' => fn () => $pinned()->update([$pinned()->qualifyColumn($column) => 2]),
            'increment_of_company_column' => fn () => $pinned()->increment($column),
            'increment_extra' => fn () => $pinned()->increment('id', 1, [$column => 2]),
            'increment_each_extra' => fn () => $pinned()->incrementEach(['id' => 1], [$column => 2]),
            'decrement_extra' => fn () => $pinned()->decrement('id', 1, [$column => 2]),
            'decrement_each_extra' => fn () => $pinned()->decrementEach(['id' => 1], [$column => 2]),
            'update_or_insert' => fn () => $pinned()->updateOrInsert(['id' => 1], [$column => 2]),
            'upsert_rows' => fn () => $pinned()->upsert([['id' => 1, $column => 2]], ['id']),
            'upsert_flat_row' => fn () => $pinned()->upsert(['id' => 1, $column => 2], ['id']),
            'upsert_update_list' => fn () => $pinned()->upsert([['id' => 1, $column => 2]], ['id'], [$column]),
            'upsert_update_map' => fn () => $pinned()->upsert([['id' => 1, $column => 2]], ['id'], [$column => 2]),
            'to_base_update' => fn () => $pinned()->toBase()->update([$column => 2]),
            'get_query_update' => fn () => $pinned()->getQuery()->update([$column => 2]),
        ];

        $exercised = 0;

        foreach ($routes as $route => $write) {
            $exercised++;

            try {
                $write();
                $violations[] = "{$route}_moves_a_company_column";
            } catch (CompanyMoveRefusedException) {
                // Expected: the row would have left its company.
            } catch (\Throwable $past) {
                // Anything else means the write got past the refusal and hit
                // the database (or a route that no longer exists). Recorded
                // against the route, never allowed to abort the loop: a loop
                // that stops early reports exactly like one that finished
                // (#32), and the count below makes that impossible to miss.
                $violations[] = "{$route}_moves_a_company_column:".$past::class;
            }
        }

        // A stated move runs, once. The second write on the same builder is
        // refused, and so is a clone taken while the grant was still armed,
        // because the grant is shared by reference rather than copied.
        $stated = $pinned()->movingCompany('Contract check: the stated move must actually be allowed to run, once.');
        $clone = clone $stated;

        try {
            $stated->update([$column => 2]);
        } catch (CompanyMoveRefusedException) {
            $violations[] = 'stated_move_is_refused';
        }

        foreach (['same_builder' => $stated, 'clone_taken_while_armed' => $clone] as $label => $spent) {
            try {
                $spent->update([$column => 2]);
                $violations[] = "grant_covers_a_second_write_on_{$label}";
            } catch (CompanyMoveRefusedException) {
                // Expected: one statement, one write.
            }
        }

        try {
            $pinned()->movingCompany('   ');
            $violations[] = 'move_accepts_an_empty_reason';
        } catch (CompanyMoveRefusedException $exception) {
            if (! str_contains($exception->getMessage(), 'reason')) {
                $violations[] = 'move_accepts_an_empty_reason';
            }
        }

        // A statement covers one write even when that write is an
        // updateOrInsert() that never reaches update(): the insert branch,
        // an empty value list, or a throw (#31). Here every attempt throws
        // against the unused tenant, which is the hardest of the three.
        foreach ([
            'update_or_insert_that_throws' => fn (Builder $armed) => $armed->updateOrInsert(['id' => 1], [$column => 2]),
            'update_or_insert_with_empty_values' => fn (Builder $armed) => $armed->updateOrInsert(['id' => 1], []),
        ] as $label => $attempt) {
            $armed = $pinned()->movingCompany('Contract check: a stated updateOrInsert must spend the statement whatever branch it takes.');

            try {
                // Savepoint-wrapped: the failed INSERT would otherwise poison
                // the test transaction on Postgres and every later statement
                // in this check would fail for that reason instead of its own.
                DB::transaction(fn () => $attempt($armed));
            } catch (\Throwable) {
                // The write itself may fail; the grant must be gone regardless.
            }

            try {
                $armed->update([$column => 2]);
                $violations[] = "grant_survives_{$label}";
            } catch (CompanyMoveRefusedException) {
                // Expected: spent.
            }
        }

        // Model routes, with events silenced: a concrete model's own
        // listeners may refuse a bare instance for their own reasons before
        // the write is built, and events are not the mechanism here — the
        // base builder sees the UPDATE a save() issues whether or not any
        // listener ran. saveQuietly() is the route an author would reach for
        // to get past a listener, so it is the one that must not get past this.
        $held = fn (): Model => tap(new $model, function (Model $instance) use ($column): void {
            $instance->setAttribute('id', 1);
            $instance->setAttribute($column, 1);
            $instance->syncOriginal();
            $instance->exists = true;
            $instance->setAttribute($column, 2);
        });

        $modelRoutes = [
            'save_quietly' => fn () => $held()->saveQuietly(),
            'save_without_events' => fn () => $model::withoutEvents(fn () => $held()->save()),
            'model_update_quietly' => fn () => $held()->updateQuietly([$column => 2]),
        ];

        foreach ($modelRoutes as $route => $write) {
            $exercised++;

            try {
                $write();
                $violations[] = "{$route}_moves_a_company_column";
            } catch (CompanyMoveRefusedException) {
                // Expected.
            } catch (\Throwable $past) {
                // Anything past the refusal (a query against a row that does
                // not exist) means the guard let the move through.
                $violations[] = "{$route}_moves_a_company_column:".$past::class;
            }
        }

        // A stated move on a model covers one save() and is spent even when
        // that save is stopped before it reaches the database.
        $instance = $held()->movingCompany('Contract check: the model statement must be spent by one save.');
        $haltOnce = true;
        $instance::updating(function () use (&$haltOnce): ?bool {
            if ($haltOnce) {
                $haltOnce = false;

                return false;
            }

            return null;
        });

        try {
            DB::transaction(fn () => $instance->save());
        } catch (\Throwable) {
            // Whatever a halted save does, the grant must be gone afterwards.
        }

        try {
            $instance->setAttribute($column, 3);
            $instance->saveQuietly();
            $violations[] = 'model_grant_survives_a_halted_save';
        } catch (CompanyMoveRefusedException) {
            // Expected.
        } catch (\Throwable $past) {
            $violations[] = 'model_grant_survives_a_halted_save:'.$past::class;
        }

        $expected = count($routes) + count($modelRoutes);

        if ($exercised !== $expected) {
            $violations[] = "routes_exercised:{$exercised}_of_{$expected}";
        }

        return $violations;
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

    /**
     * Application code that opens the guard without stating a reason.
     *
     * `withoutCompanyScope($reason)` is the sanctioned escape, and the reason
     * is what makes the grep in docs/contracts/company-ownership.md a complete
     * list. Laravel's own scope-removal methods open the same guard silently
     * and appear in no such grep, so this turns the document's completeness
     * claim into something the suite enforces rather than something the reader
     * has to trust.
     *
     * It is not airtight, and the contract says which gap is left rather than
     * implying there is none.
     *
     * The trait's own sanctioned call is the single exception.
     *
     * @return list<string> "relative/path.php:line"
     */
    public static function unreasonedGuardBypasses(): array
    {
        $domainRoot = dirname(__DIR__, 2);
        $allowed = [
            $domainRoot.'/Connector/Models/Concerns/CompanyOwned.php',
            __FILE__,
        ];
        $found = [];

        foreach (self::phpFiles($domainRoot) as $file) {
            $path = $file->getPathname();

            if (in_array($path, $allowed, true)) {
                continue;
            }

            foreach (self::guardRemovalCalls($path) as $line) {
                $found[] = substr($path, strlen($domainRoot) + 1).':'.$line;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Model files, found by directory rather than by declaration.
     *
     * A company-owned model placed outside a `Models` directory enrols itself
     * into nothing, silently. That is a deliberate trade — parsing every file
     * in the domain to find Eloquent subclasses costs more than it buys while
     * the house layout puts models in `Models` — but it is a real limit, and
     * the companion "the repository actually contains company-owned models"
     * test only catches total discovery failure, not one missed model.
     *
     * @return list<\SplFileInfo>
     */
    private static function modelFiles(): array
    {
        $domainRoot = dirname(__DIR__, 2);
        $files = [];

        foreach (self::phpFiles($domainRoot) as $file) {
            if (str_contains(str_replace('\\', '/', $file->getPath()), '/Models')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Lines calling Laravel's own scope-removal methods, found by tokenizing
     * rather than by matching text — a comment or a docblock that names the
     * method is discussing it, not calling it.
     *
     * @return list<int>
     */
    private static function guardRemovalCalls(string $path): array
    {
        // Every Eloquent method whose purpose is removing a global scope.
        // Deliberately not here: getQuery(), which steps out of Eloquent onto
        // the underlying query builder. It opens the guard just as
        // effectively, but it is an ordinary, benign method with many honest
        // uses across models that are not company-owned, and a lint that
        // flags those gets weakened until it means nothing. It sits in the
        // same category as DB::table() — leaving Eloquent, covered by the
        // contract's rule rather than by this check.
        $removers = [
            'withoutGlobalScope',
            'withoutGlobalScopes',
            'newQueryWithoutScope',
            'newQueryWithoutScopes',
        ];
        $lines = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $removers, true)) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /** @return list<\SplFileInfo> */
    private static function phpFiles(string $root): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
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
