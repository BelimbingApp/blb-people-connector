<?php

namespace App\Domains\PeopleConnector\Skill;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-connector-skill');
    }
}
