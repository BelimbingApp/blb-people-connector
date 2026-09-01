<?php

use App\Domains\PeopleConnector\Skill\Livewire\Catalog\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/skills', Index::class)
        ->middleware('authz:people-connector.skill.catalog.view')
        ->name('people-connector.skill.catalog.index');
});
