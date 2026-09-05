<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Livewire\Connections\Index as Connections;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index as Reconciliation;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderHealthStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function operatorActionAuthorization(): AuthorizationService
{
    $service = new class implements AuthorizationService
    {
        public bool $allowed = true;

        public array $calls = [];

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->calls[] = [$actor->id, $capability];
            abort_unless($this->allowed, 403);
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect();
        }
    };
    app()->instance(AuthorizationService::class, $service);

    return $service;
}

function operatorHealthProvider(): ProviderAdapter
{
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('descriptor')->andReturn(new ProviderDescriptor('operator.test', 'Operator test', '0.1.0', '1.0.0'));
    $provider->shouldReceive('capabilities')->andReturn(new CapabilitySet([]));
    $registry = new ProviderRegistry;
    $registry->register($provider);
    app()->instance(ProviderRegistry::class, $registry);

    return $provider;
}

it('refreshes health only for the current authorized actor and records the result', function () {
    $user = createAdminUser();
    $authorization = operatorActionAuthorization();
    operatorHealthProvider()->shouldReceive('health')->once()->andReturn(new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-05T12:00:00Z')));

    Livewire::actingAs($user)->test(Connections::class)->call('refreshHealth', 'operator.test')->assertHasNoErrors();

    expect($authorization->calls)->toContain([(int) $user->id, 'people-connector.connection.list'])
        ->and(app(ProviderHealthStore::class)->snapshot('operator.test')->state)->toBe(ProviderHealthState::Healthy);
});

it('refuses a revoked operator grant before calling the provider', function () {
    $user = createAdminUser();
    $authorization = operatorActionAuthorization();
    $provider = operatorHealthProvider();
    // Count side effects directly: swallowed adapter exceptions must not conceal a call.
    $calls = 0;
    $provider->shouldReceive('health')->andReturnUsing(function () use (&$calls) {
        $calls++;

        return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable);
    });
    $component = Livewire::actingAs($user)->test(Connections::class);
    $authorization->allowed = false;
    $component->call('refreshHealth', 'operator.test')->assertForbidden();
    expect($calls)->toBe(0)
        ->and(app(ProviderHealthStore::class)->snapshot('operator.test')->state)->toBe(ProviderHealthState::Unknown);
});

it('refuses health refresh for an actor outside the current tenant', function () {
    $user = createAdminUser();
    operatorActionAuthorization();
    operatorHealthProvider()->shouldNotReceive('health');
    $component = Livewire::actingAs($user)->test(Connections::class);
    [$otherTenant] = createTenantWithCompany(['name' => 'Other operator tenant']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    $component->call('refreshHealth', 'operator.test')->assertForbidden();
});

it('does not probe a different provider when the requested identifier is unknown', function () {
    $user = createAdminUser();
    operatorActionAuthorization();
    operatorHealthProvider()->shouldNotReceive('health');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshHealth', 'missing.provider')->assertHasNoErrors()->assertNotDispatched('notify');
    expect(app(ProviderHealthStore::class)->snapshot('operator.test')->state)->toBe(ProviderHealthState::Unknown);
});

function operatorReconciliationFixture(string $action): array
{
    $user = createAdminUser();
    $authorization = operatorActionAuthorization();
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company((int) $user->company_id), 'operator.test');
    $connection = $connections->activate((int) $connection->id);
    $identities = app(WorkforceIdentityStore::class);
    $old = new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-OLD');
    $survivor = new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-SURVIVOR');
    $at = now()->subHour();
    $oldIdentity = $identities->resolveOrCreateIdentity((int) $connection->id, $old, $at);
    $survivingIdentity = $identities->resolveOrCreateIdentity((int) $connection->id, $survivor, $at);
    $issue = app(ReconciliationIssueStore::class)->report(
        (int) $connection->id,
        'operator:review',
        $action === 'applyMerge' ? 'sync_merge_requested' : 'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required', relatedExternalId: 'OP-SURVIVOR'),
        WorkforceResourceType::Employee->value,
        'OP-OLD',
        seenAt: $at,
    );
    $component = Livewire::actingAs($user)->test(Reconciliation::class, ['connectionId' => (int) $connection->id])
        ->set('resolutionNotes.'.$issue->id, 'Reviewed source evidence.')
        ->set('reviewReferences.'.$issue->id, 'review-operator-136')
        ->set('replacementExternalIds.'.$issue->id, 'OP-REPLACEMENT');

    return [$component, $issue, $authorization, $connection, $oldIdentity, $survivingIdentity];
}

it('rechecks the operator grant on each reconciliation action after the page opens', function (string $action) {
    [$component, $issue, $authorization, $connection, $oldIdentity] = operatorReconciliationFixture($action);
    $authorization->allowed = false;
    $component->call($action, (int) $issue->id)->assertForbidden();
    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and(app(WorkforceIdentityStore::class)->resolve((int) $connection->id, new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-OLD'))->id)->toBe($oldIdentity->workforce_entity_id);
})->with(['resolveIssue', 'applyMerge', 'remapIdentity']);

it('commits the authorized operator decision and reports success', function (string $action) {
    [$component, $issue, $authorization, $connection, $oldIdentity, $survivingIdentity] = operatorReconciliationFixture($action);
    $component->call($action, (int) $issue->id)->assertHasNoErrors()->assertDispatched('notify');
    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED);
    $identities = app(WorkforceIdentityStore::class);
    if ($action === 'applyMerge') {
        expect($identities->resolve((int) $connection->id, new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-OLD'))->id)->toBe($survivingIdentity->workforce_entity_id);
    } elseif ($action === 'remapIdentity') {
        expect($identities->resolve((int) $connection->id, new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-REPLACEMENT'))->id)->toBe($oldIdentity->workforce_entity_id);
    }
})->with(['resolveIssue', 'applyMerge', 'remapIdentity']);

it('refuses invalid decision input without resolving or changing the identity', function (string $action, string $field, string $value) {
    [$component, $issue, $authorization, $connection, $oldIdentity] = operatorReconciliationFixture($action);
    $component->set($field.'.'.$issue->id, $value)->call($action, (int) $issue->id)->assertHasErrors([$field.'.'.$issue->id])->assertNotDispatched('notify');
    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN)
        ->and(app(WorkforceIdentityStore::class)->resolve((int) $connection->id, new ExternalReference('operator.test', WorkforceResourceType::Employee, 'OP-OLD'))->id)->toBe($oldIdentity->workforce_entity_id);
})->with([
    ['resolveIssue', 'resolutionNotes', ''],
    ['applyMerge', 'reviewReferences', 'not a reference'],
    ['remapIdentity', 'replacementExternalIds', ''],
]);
