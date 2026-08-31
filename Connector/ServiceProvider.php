<?php

namespace App\Domains\PeopleConnector\Connector;

use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
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
