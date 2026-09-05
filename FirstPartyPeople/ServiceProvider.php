<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople;

use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    /**
     * Installing this module makes the co-located adapter available; the
     * deployment's `active_provider` decides whether it is the provider in
     * use. Registration hangs off the registry's first resolution rather than
     * off boot so the config the deployment sets is the config that is read.
     *
     * The adapter is deliberately left unbound. It reaches People through
     * readers that inject the scoped TenantContext, and Octane keeps a
     * singleton alive across the request boundary that discards that scope —
     * so this one is built fresh per resolution, as the sibling Connector
     * module's provider documents.
     */
    public function register(): void
    {
        $this->app->resolving(
            ProviderRegistry::class,
            static function (ProviderRegistry $registry, Application $app): void {
                if ($registry->configuredProviderId() !== FirstPartyPeopleAdapter::ID) {
                    return;
                }

                $registry->register($app->make(FirstPartyPeopleAdapter::class));
            },
        );
    }
}
