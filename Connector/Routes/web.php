<?php

use App\Domains\PeopleConnector\Connector\Http\Controllers\WorkforceWebhookController;
use App\Domains\PeopleConnector\Connector\Livewire\Audit\Index as AuditIndex;
use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index as ReconciliationIndex;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/people-connector/{connectionId}', WorkforceWebhookController::class)
    ->whereNumber('connectionId')
    ->name('people-connector.webhook');

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/integration/people-connections', Index::class)
        ->middleware('authz:people-connector.connection.list')
        ->name('admin.people-connector.connections.index');

    Route::get('admin/integration/people-connections/{connectionId}/reconciliation', ReconciliationIndex::class)
        ->middleware('authz:people-connector.identity.manage')
        ->name('admin.people-connector.reconciliation.index');

    Route::get('admin/integration/people-connections/{connectionId}/audit', AuditIndex::class)
        ->middleware('authz:people-connector.connection.list')
        ->name('admin.people-connector.audit.index');
});
