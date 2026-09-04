<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use Illuminate\Support\Collection;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function reconciliationPageAllowingAuthz(): void
{
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

function reconciliationPageConnection(int $tenantId, int $companyId): int
{
    app(TenantContext::class)->set($tenantId);
    $connection = app(ProviderConnectionStore::class)->configure(ProviderScope::company($companyId), 'test.reconciliation');

    return (int) app(ProviderConnectionStore::class)->activate((int) $connection->id)->id;
}

test('an attributed identity manager resolves one issue and records the resolution note', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Page Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $recorded = [];
    app()->instance(SemanticActionRecorder::class, new class($recorded) implements SemanticActionRecorder
    {
        /** @param array<int, array<string, mixed>> $recorded */
        public function __construct(private array &$recorded) {}

        public function record(string $event, string $summary, ?string $source = null, array $subject = [], ?string $surface = null, ?string $uiElement = null, array $context = [], string $result = 'succeeded', bool $retain = true): void
        {
            $this->recorded[] = compact('event', 'subject', 'surface', 'context');
        }
    });
    $issue = app(ReconciliationIssueStore::class)->report(
        $connectionId,
        'sync:employee:EMP-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'record_not_found'),
        WorkforceResourceType::Employee->value,
        'EMP-1',
    );

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('resolutionNotes.'.$issue->id, 'Confirmed duplicate source record.')
        ->call('resolveIssue', (int) $issue->id)
        ->assertHasNoErrors();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and($recorded)->toContain([
            'event' => 'people_connector.reconciliation.resolved',
            'subject' => ['name' => 'reconciliation_issue', 'id' => $issue->id, 'identifier' => $issue->issue_key],
            'surface' => 'admin.people-connector.reconciliation.index',
            'context' => ['note' => 'Confirmed duplicate source record.'],
        ]);
});

test('a sibling-company user cannot open a company-scoped reconciliation queue', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $connectionId = reconciliationPageConnection($fixture->tenantId, (int) $fixture->alphaCompany->id);
    $betaUser = User::factory()->create(['company_id' => $fixture->betaCompany->id]);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($betaUser)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertForbidden();
});

test('a reviewed remap resolves the queue issue and records its provenance reference', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Remap Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $at = new DateTimeImmutable('2026-09-04T11:00:00+00:00');
    $old = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-OLD');
    app(WorkforceIdentityStore::class)->resolveOrCreateIdentity($connectionId, $old, $at);
    $issue = app(ReconciliationIssueStore::class)->report(
        $connectionId,
        'sync:employee:EMP-OLD',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'identity_collision'),
        WorkforceResourceType::Employee->value,
        'EMP-OLD',
        seenAt: $at,
    );

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('replacementExternalIds.'.$issue->id, 'EMP-NEW')
        ->set('reviewReferences.'.$issue->id, 'review-2026-09-04')
        ->call('remapIdentity', (int) $issue->id)
        ->assertHasNoErrors();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and(app(WorkforceIdentityStore::class)->resolve($connectionId, new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-NEW'))->id)
        ->toBe(app(WorkforceIdentityStore::class)->resolve($connectionId, $old)->id);
});
