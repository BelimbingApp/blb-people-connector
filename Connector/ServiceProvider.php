<?php

namespace App\Domains\PeopleConnector\Connector;

use App\Domains\PeopleConnector\Connector\Console\Commands\SyncWorkforceCommand;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use Illuminate\Console\Scheduling\Schedule;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWorkforceCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-connector');

        $this->app->booted(function (): void {
            // Cadence must stay below max_age_minutes so freshness does not
            // lapse between ticks by design (#70). Default max age is 24h;
            // hourly is safely under that without thrashing adapters.
            $maxAge = WorkforceFreshnessPolicy::maxAgeMinutes();
            $minutes = max(1, min(60, intdiv($maxAge, 4)));

            $this->app->make(Schedule::class)
                ->command('people-connector:sync')
                ->cron("*/{$minutes} * * * *")
                ->onOneServer()
                ->withoutOverlapping($minutes);
        });
    }
}
