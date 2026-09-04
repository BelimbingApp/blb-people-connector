<?php

use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index as ReconciliationIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/integration/people-connections', Index::class)
        ->middleware('authz:people-connector.connection.list')
        ->name('admin.people-connector.connections.index');

    Route::get('admin/integration/people-connections/{connectionId}/reconciliation', ReconciliationIndex::class)
        ->middleware('authz:people-connector.identity.manage')
        ->name('admin.people-connector.reconciliation.index');
});
