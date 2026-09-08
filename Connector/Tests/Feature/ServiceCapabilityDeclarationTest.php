<?php

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Services\OperatorIdentityReader;
use App\Domains\PeopleConnector\Connector\Services\PrivacyDeletionService;

/**
 * Every capability a Connector service authorizes against must be declared.
 *
 * `people-connector.identity.purge` was not. PrivacyDeletionService authorized
 * against it, Connector/Config/authz.php never listed it, and the platform's
 * KnownCapabilityPolicy denies anything the registry does not hold — so
 * privacy erasure was unreachable for every actor in production (#325).
 *
 * The eleven erasure tests all stub AuthorizationService, so none of them ever
 * consulted the registry. ProviderPortCapabilityGrammarTest was written for
 * this failure mode but walks capabilities that are already declared, which
 * makes an omission structurally invisible to it. This walks the other
 * direction: from what the services use to what the config declares.
 */

/**
 * Capability strings held in `*_CAPABILITY` constants on Connector services.
 *
 * Derived by reflection, never hand-listed: a hand-written list is exactly
 * what let the erase capability go missing, and the next omission would be
 * just as invisible.
 *
 * @return array<string, string> capability => the constant that declares it
 */
function connectorServiceCapabilities(): array
{
    $found = [];

    foreach (glob(__DIR__.'/../../Services/*.php') ?: [] as $file) {
        $class = 'App\\Domains\\PeopleConnector\\Connector\\Services\\'.basename($file, '.php');
        if (! class_exists($class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
            if (! str_ends_with($name, 'CAPABILITY') || ! is_string($value)) {
                continue;
            }
            if (str_starts_with($value, 'people-connector.')) {
                $found[$value] = class_basename($class).'::'.$name;
            }
        }
    }

    ksort($found);

    return $found;
}

it('declares every capability a Connector service authorizes against', function (): void {
    $used = connectorServiceCapabilities();

    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');
    $registry = CapabilityRegistry::fromCatalog(CapabilityCatalog::fromConfig($authzConfig));

    // A reflection walk that finds nothing passes silently, and a control that
    // cannot fail is not a control. belimbing gitignores the mounts, so a run
    // that resolved no Connector service would otherwise look green.
    expect($used)->not->toBeEmpty();

    $undeclared = [];
    foreach ($used as $capability => $source) {
        if (! $registry->has($capability)) {
            $undeclared[] = $capability.' ('.$source.')';
        }
    }

    expect($undeclared)->toBe([]);
});

it('reaches the erase capability the privacy service authorizes against', function (): void {
    $registry = CapabilityRegistry::fromCatalog(CapabilityCatalog::fromConfig(config('authz')));

    expect($registry->has(PrivacyDeletionService::ERASE_CAPABILITY))->toBeTrue();
});

afterEach(fn () => app(TenantContext::class)->clear());

it('enumerates the purge capability for connector:operator:whoami', function (): void {
    // whoami reads the declared list, so an undeclared capability is missing
    // from the answer entirely -- the command would promise "the same answer
    // every operator command would give" while omitting one of them.
    [$tenant, $company] = createTenantWithCompany(['name' => 'Service Capability Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $operator = User::factory()->create(['company_id' => $company->id]);

    $rows = app(OperatorIdentityReader::class)->read(Actor::forUser($operator))->capabilities;

    expect(array_column($rows, 'capability'))->toContain(PrivacyDeletionService::ERASE_CAPABILITY);
});
