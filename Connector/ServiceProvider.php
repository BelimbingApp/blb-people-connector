<?php

namespace App\Domains\PeopleConnector\Connector;

use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\PeopleConnector\Connector\Console\Commands\ConnectorDoctorCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\CutoverRehearsalCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\RetentionPurgeCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\RetentionReportCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\SyncWorkforceCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\WebhookReplayCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\WorkforceSubjectExportCommand;
use App\Domains\PeopleConnector\Connector\Console\Commands\WorkforceSubjectImportCommand;
use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Services\DelegatedCommandPort;
use App\Domains\PeopleConnector\Connector\Services\ProjectionWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
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
        // The seam is People's contract, and People binds its own native
        // resolver. Overriding that outright breaks every co-located install:
        // the connector's projections are empty there, because nothing
        // synchronizes a provider that is already in the process. So decide
        // per resolution instead of per boot, and hand back People's own
        // resolver whenever People is the authority. extend() keeps the
        // fallback without this module having to name a People service.
        //
        // Neither side is a singleton: both inject the scoped TenantContext,
        // which Octane discards across the request boundary a singleton
        // survives.
        $this->app->extend(
            ResolvesWorkforceSubjects::class,
            static fn (ResolvesWorkforceSubjects $native, Application $app): ResolvesWorkforceSubjects => self::connectorOwnsWorkforceIdentity()
                ? $app->make(ProjectionWorkforceSubjectResolver::class)
                : $native,
        );
        // Not a singleton: the port injects the scoped TenantContext, which
        // Octane discards across the request boundary a singleton survives.
        $this->app->bind(AcceptsDelegatedCommands::class, DelegatedCommandPort::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ProviderHealthStore::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CutoverRehearsalCommand::class,
                ConnectorDoctorCommand::class,
                RetentionPurgeCommand::class,
                RetentionReportCommand::class,
                SyncWorkforceCommand::class,
                WebhookReplayCommand::class,
                WorkforceSubjectExportCommand::class,
                WorkforceSubjectImportCommand::class,
            ]);
        }
    }

    /**
     * Whether workforce identity is answered from this connector's
     * projections rather than by People itself.
     *
     * A deployment that has not chosen a provider, or has chosen People, is
     * co-located: People owns the records and answers for them directly. Any
     * other adapter means the authoritative HR system is somewhere else and
     * the synchronized projections are the only local truth.
     */
    public static function connectorOwnsWorkforceIdentity(): bool
    {
        $active = config('people-connector.active_provider');

        return is_string($active)
            && trim($active) !== ''
            && $active !== PeopleExternalReference::PROVIDER_ID;
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
