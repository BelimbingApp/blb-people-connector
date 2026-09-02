<?php

namespace App\Domains\PeopleConnector\Connector;

use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     *
     * Both bindings are singletons on purpose: neither object stores anything
     * that belongs to a single request, job, or command. Tenant-dependent
     * values are resolved at the point of use instead, because Octane keeps
     * singletons alive across request boundaries while discarding the scoped
     * TenantContext.
     *
     * The constraint is on lifetime, not on injection. An unbound service is
     * built fresh on every resolution and may inject TenantContext freely, as
     * the platform's own tenancy services and the sibling Connector stores do.
     * It is registering something here that changes the calculation: anything
     * bound as a singleton must not hold per-execution state.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/people-connector.php', 'people-connector');
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ProviderHealthStore::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-connector');
    }
}
