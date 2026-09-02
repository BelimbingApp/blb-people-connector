<?php

use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/integration/people-connections', Index::class)
        ->middleware('authz:people-connector.connection.list')
        ->name('admin.people-connector.connections.index');
});
