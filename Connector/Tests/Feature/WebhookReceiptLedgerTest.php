<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WebhookReceipt;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WebhookReceiptLedger;
use Illuminate\Support\Facades\DB;

/**
 * WebhookReceiptLedger (#227, pinned by #311): the receipt insert runs in its
 * own nested transaction so a replayed delivery id's unique violation cannot
 * poison an enclosing PostgreSQL transaction (25P02). SQLite does not poison,
 * so on SQLite this file passes with or without the inner transaction; the
 * proof is the postgres-mirror lane. Self-contained: helpers are prefixed
 * receiptLedger.
 */
afterEach(fn () => app(TenantContext::class)->clear());

function receiptLedgerConnection(string $name): ProviderConnection
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);

    return $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), 'test.receipt')->id);
}

/** @return list<array{string, int}> delivery id and duplicate count per receipt of the tenant */
function receiptLedgerRows(int $tenantId): array
{
    return WebhookReceipt::query()->forTenant($tenantId)->orderBy('delivery_id')->get(['delivery_id', 'duplicate_count'])
        ->map(fn (WebhookReceipt $r): array => [$r->delivery_id, $r->duplicate_count])->all();
}

test('a replayed delivery inside an outer transaction leaves that transaction usable and both rows committed', function (): void {
    $connection = receiptLedgerConnection('Receipt Tenant');
    $sibling = receiptLedgerConnection('Receipt Sibling Tenant');
    $ledger = app(WebhookReceiptLedger::class);
    $enqueued = 0;
    $enqueue = function () use (&$enqueued): void {
        $enqueued++;
    };
    // The sibling tenant accepted the same delivery id first: its row is the control.
    expect($ledger->acceptOnce((int) $sibling->tenant_id, $sibling, 'delivery-replayed', $enqueue))->toBeTrue();
    $siblingBefore = receiptLedgerRows((int) $sibling->tenant_id);
    expect($siblingBefore)->toBe([['delivery-replayed', 0]]);

    $tenantId = (int) $connection->tenant_id;
    DB::transaction(function () use ($ledger, $connection, $tenantId, $enqueue): void {
        expect($ledger->acceptOnce($tenantId, $connection, 'delivery-replayed', $enqueue))->toBeTrue()
            ->and($ledger->acceptOnce($tenantId, $connection, 'delivery-replayed', $enqueue))->toBeFalse();
        // An unrelated write in the same outer transaction: on PostgreSQL this
        // is the statement that fails with 25P02 once a violation poisoned it.
        WebhookReceipt::query()->create(['tenant_id' => $tenantId, 'provider_id' => (string) $connection->provider_id, 'connection_id' => (int) $connection->id, 'delivery_id' => 'delivery-unrelated', 'first_seen_at' => now()]);
    });

    expect($enqueued)->toBe(2)
        ->and(receiptLedgerRows($tenantId))->toBe([['delivery-replayed', 1], ['delivery-unrelated', 0]])
        ->and(receiptLedgerRows((int) $sibling->tenant_id))->toBe($siblingBefore);
});
