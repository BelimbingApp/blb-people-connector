<?php

use App\Domains\PeopleConnector\Skill\Livewire\Assessment\Matrix;
use App\Domains\PeopleConnector\Skill\Livewire\Catalog\Index;
use App\Domains\PeopleConnector\Skill\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/skills', Index::class)
        ->middleware('authz:people-connector.skill.catalog.view')
        ->name('people-connector.skill.catalog.index');

    Route::get('people/skill-assessments', Matrix::class)
        ->middleware('authz:people-connector.skill.assessment.view')
        ->name('people-connector.skill.assessment.matrix');

    Route::get('people/development-actions', DevelopmentActionIndex::class)
        ->middleware('authz:people-connector.skill.development-action.view')
        ->name('people-connector.skill.development-actions.index');
});
