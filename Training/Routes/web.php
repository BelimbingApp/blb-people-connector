<?php

use App\Domains\PeopleConnector\Training\Http\Middleware\AuthorizeTrainingAudience;
use App\Domains\PeopleConnector\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\PeopleConnector\Training\Livewire\Event\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/training-catalog', CatalogIndex::class)
        ->middleware('authz:people-connector.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people-connector.training.catalog.index');

    Route::get('people/training-events', Index::class)
        ->middleware('authz:people-connector.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people-connector.training.events.index');
});
