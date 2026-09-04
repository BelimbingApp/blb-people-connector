<?php

namespace App\Domains\PeopleConnector\NativePeople;

use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap as ReadsNativeWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsNativeWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\NativePeople\Providers\NativePeopleAdapter;
use App\Domains\PeopleConnector\NativePeople\Providers\NativePeopleWorkforceSource;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // These singletons contain only the application container and a
        // stateless mapper. Provider readers, and therefore TenantContext,
        // are resolved afresh inside each port call for Octane safety.
        $this->app->singleton(NativePeopleWorkforceMapper::class);
        $this->app->singleton(NativePeopleWorkforceSource::class);
        $this->app->singleton(NativePeopleAdapter::class);
    }

    public function boot(): void
    {
        if (! interface_exists(ReadsNativeWorkforceBootstrap::class)
            || ! interface_exists(ReadsNativeWorkforceChanges::class)) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(ProviderRegistry::class)
                ->register($this->app->make(NativePeopleAdapter::class));
        });
    }
}
