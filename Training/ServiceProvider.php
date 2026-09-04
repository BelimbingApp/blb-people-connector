<?php

namespace App\Domains\PeopleConnector\Training;

use App\Domains\PeopleConnector\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\PeopleConnector\Training\Services\UnavailableTrainingParticipationSummary;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SummarizesTrainingParticipation::class,
            UnavailableTrainingParticipationSummary::class,
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-connector-training');
    }
}
