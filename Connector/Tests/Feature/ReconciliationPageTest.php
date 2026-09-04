<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ReconciliationIssueConflictException;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationReviewService;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Date::setTestNow();
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

function reconciliationPageDenyingAuthz(): void
{
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            abort(403);
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect();
        }
    });
}

/** @param array<int, array<string, mixed>> $recorded */
function reconciliationPageCaptureActions(array &$recorded): void
{
    app()->instance(SemanticActionRecorder::class, new class($recorded) implements SemanticActionRecorder
    {
        /** @param array<int, array<string, mixed>> $recorded */
        public function __construct(private array &$recorded) {}

        public function record(string $event, string $summary, ?string $source = null, array $subject = [], ?string $surface = null, ?string $uiElement = null, array $context = [], string $result = 'succeeded', bool $retain = true): void
        {
            $this->recorded[] = compact('event', 'subject', 'surface', 'context');
        }
    });
}

function reconciliationPageConnection(int $tenantId, ?int $companyId): int
{
    app(TenantContext::class)->set($tenantId);
    $scope = $companyId === null ? ProviderScope::tenant() : ProviderScope::company($companyId);
    $connection = app(ProviderConnectionStore::class)->configure($scope, 'test.reconciliation');

    return (int) app(ProviderConnectionStore::class)->activate((int) $connection->id)->id;
}

test('an attributed identity manager resolves one issue and records the resolution note', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Page Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $recorded = [];
    reconciliationPageCaptureActions($recorded);
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
    $recorded = [];
    reconciliationPageCaptureActions($recorded);
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
        ->toBe(app(WorkforceIdentityStore::class)->resolve($connectionId, $old)->id)
        ->and($recorded)->toContain([
            'event' => 'people_connector.reconciliation.identity_remapped',
            'subject' => ['name' => 'reconciliation_issue', 'id' => $issue->id, 'identifier' => $issue->issue_key],
            'surface' => 'admin.people-connector.reconciliation.index',
            'context' => [
                'review_reference' => 'review-2026-09-04',
                'replacement_external_id' => 'EMP-NEW',
            ],
        ]);
});

test('identity management permission is required before a queue can be opened', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Permission Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageDenyingAuthz();

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertForbidden();
});

test('a connection id from another tenant is never visible to an identity manager', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Reconciliation Tenant A']);
    $connectionId = reconciliationPageConnection((int) $tenantA->id, (int) $companyA->id);
    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Reconciliation Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    $user = User::factory()->create(['company_id' => $companyB->id]);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertNotFound();
});

test('an issue id from another tenant cannot be resolved through an allowed connection', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Reconciliation Issue Tenant A']);
    $connectionA = reconciliationPageConnection((int) $tenantA->id, (int) $companyA->id);
    $foreignIssue = app(ReconciliationIssueStore::class)->report(
        $connectionA,
        'sync:employee:FOREIGN-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'identity_collision'),
        WorkforceResourceType::Employee->value,
        'FOREIGN-1',
    );
    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Reconciliation Issue Tenant B']);
    $connectionB = reconciliationPageConnection((int) $tenantB->id, (int) $companyB->id);
    $user = User::factory()->create(['company_id' => $companyB->id]);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionB])
        ->set('resolutionNotes.'.$foreignIssue->id, 'Attempted cross-tenant resolution.')
        ->call('resolveIssue', (int) $foreignIssue->id)
        ->assertNotFound();

    expect($foreignIssue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('a tenant-scoped queue is available in the single-company carve-out', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Single Company Reconciliation Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, null);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertOk()
        ->assertSee('Reconciliation queue');
});

test('the single-company carve-out never admits an actor from another tenant', function (): void {
    [$tenantA] = createTenantWithCompany(['name' => 'Single Company Tenant A']);
    $connectionId = reconciliationPageConnection((int) $tenantA->id, null);
    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Single Company Tenant B']);
    $foreignUser = User::factory()->create(['company_id' => $companyB->id]);
    app(TenantContext::class)->set((int) $tenantA->id);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($foreignUser)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertForbidden();
});

test('a tenant-scoped queue fails closed when the tenant has multiple companies', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Multi Company Reconciliation Tenant']);
    Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sibling Company']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, null);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertForbidden();
});

test('a reviewed merge resolves through Livewire and audits the locked survivor evidence', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Merge Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $recorded = [];
    reconciliationPageCaptureActions($recorded);
    $at = new DateTimeImmutable('2026-09-04T11:00:00+00:00');
    Date::setTestNow($at->modify('+1 hour'));
    $old = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-MERGE-OLD');
    $staleSurvivor = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-MERGE-STALE');
    $survivor = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-MERGE-NEW');
    $identities = app(WorkforceIdentityStore::class);
    $identities->resolveOrCreateIdentity($connectionId, $old, $at);
    $identities->resolveOrCreateIdentity($connectionId, $staleSurvivor, $at);
    $survivingIdentity = $identities->resolveOrCreateIdentity($connectionId, $survivor, $at);
    $issues = app(ReconciliationIssueStore::class);
    $issue = $issues->report(
        $connectionId,
        'sync:merge:employee:EMP-MERGE-OLD',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'EMP-MERGE-STALE'),
        WorkforceResourceType::Employee->value,
        'EMP-MERGE-OLD',
        seenAt: $at,
    );

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId]);

    // Simulate fresh provider evidence arriving after the operator opened the
    // page. The decision and audit must both use the row locked by the service.
    $issues->report(
        $connectionId,
        'sync:merge:employee:EMP-MERGE-OLD',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'EMP-MERGE-NEW'),
        WorkforceResourceType::Employee->value,
        'EMP-MERGE-OLD',
        seenAt: $at->modify('+30 minutes'),
    );

    $component
        ->set('reviewReferences.'.$issue->id, 'review-merge-84')
        ->call('applyMerge', (int) $issue->id)
        ->assertHasNoErrors();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and($identities->resolve($connectionId, $old)->id)->toBe($survivingIdentity->workforce_entity_id)
        ->and($recorded)->toContain([
            'event' => 'people_connector.reconciliation.merge_applied',
            'subject' => ['name' => 'reconciliation_issue', 'id' => $issue->id, 'identifier' => $issue->issue_key],
            'surface' => 'admin.people-connector.reconciliation.index',
            'context' => [
                'review_reference' => 'review-merge-84',
                'surviving_external_id' => 'EMP-MERGE-NEW',
            ],
        ]);
});

test('invalid review references and legacy merge evidence fail closed at the component boundary', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Legacy Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $at = new DateTimeImmutable('2026-09-04T11:00:00+00:00');
    Date::setTestNow($at->modify('+1 hour'));
    $old = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-LEGACY-OLD');
    $new = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-LEGACY-NEW');
    app(WorkforceIdentityStore::class)->resolveOrCreateIdentity($connectionId, $old, $at);
    app(WorkforceIdentityStore::class)->resolveOrCreateIdentity($connectionId, $new, $at);
    $issues = app(ReconciliationIssueStore::class);
    $valid = $issues->report(
        $connectionId,
        'sync:merge:employee:EMP-LEGACY-VALID',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'EMP-LEGACY-NEW'),
        WorkforceResourceType::Employee->value,
        'EMP-LEGACY-OLD',
        seenAt: $at,
    );

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('reviewReferences.'.$valid->id, 'not a review reference')
        ->call('applyMerge', (int) $valid->id)
        ->assertHasErrors(['reviewReferences.'.$valid->id]);

    $issues->resolve((int) $valid->id, $at->modify('+30 minutes'));

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('reviewReferences.'.$valid->id, 'review-closed-84')
        ->call('applyMerge', (int) $valid->id)
        ->assertNotFound();

    $legacy = $issues->report(
        $connectionId,
        'sync:merge:employee:EMP-LEGACY-OLD',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'EMP-LEGACY-OLD',
        seenAt: $at,
    );

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('reviewReferences.'.$legacy->id, 'review-legacy-84')
        ->call('applyMerge', (int) $legacy->id)
        ->assertHasErrors(['reviewReferences.'.$legacy->id]);

    $malformed = $issues->report(
        $connectionId,
        'sync:merge:employee:EMP-LEGACY-MALFORMED',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'EMP-LEGACY-OLD'),
        WorkforceResourceType::Employee->value,
        'EMP-LEGACY-OLD',
        seenAt: $at,
    );

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('reviewReferences.'.$malformed->id, 'review-malformed-84')
        ->call('applyMerge', (int) $malformed->id)
        ->assertHasErrors(['reviewReferences.'.$malformed->id]);

    expect($legacy->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($malformed->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($valid->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED);
});

test('a post-mutation resolution rejection rolls back the reviewed merge decision', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reconciliation Rollback Tenant']);
    $connectionId = reconciliationPageConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    reconciliationPageAllowingAuthz();
    $decisionAt = new DateTimeImmutable('2026-09-04T12:00:00+00:00');
    Date::setTestNow($decisionAt);
    $observedAt = $decisionAt->modify('-1 hour');
    $old = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-ROLLBACK-OLD');
    $survivor = new ExternalReference('test.reconciliation', WorkforceResourceType::Employee, 'EMP-ROLLBACK-NEW');
    $identities = app(WorkforceIdentityStore::class);
    $oldIdentity = $identities->resolveOrCreateIdentity($connectionId, $old, $observedAt);
    $survivorIdentity = $identities->resolveOrCreateIdentity($connectionId, $survivor, $observedAt);
    $issue = app(ReconciliationIssueStore::class)->report(
        $connectionId,
        'sync:merge:employee:EMP-ROLLBACK-OLD',
        'sync_merge_requested',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'EMP-ROLLBACK-NEW'),
        WorkforceResourceType::Employee->value,
        'EMP-ROLLBACK-OLD',
        seenAt: $decisionAt->modify('+1 hour'),
    );

    expect(fn () => app(ReconciliationReviewService::class)->applyMerge(
        $connectionId,
        (int) $issue->id,
        'review-rollback-84',
        $decisionAt,
    ))
        ->toThrow(ReconciliationIssueConflictException::class, 'cannot resolve before its latest observation')
        ->and($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and($identities->resolve($connectionId, $old)->id)->toBe($oldIdentity->workforce_entity_id)
        ->and($identities->resolve($connectionId, $survivor)->id)->toBe($survivorIdentity->workforce_entity_id)
        ->and($oldIdentity->workforce_entity_id)->not->toBe($survivorIdentity->workforce_entity_id);
});
