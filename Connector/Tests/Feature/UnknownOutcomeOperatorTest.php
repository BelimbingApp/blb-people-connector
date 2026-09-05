<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Enums\CommandResolution;
use App\Domains\PeopleConnector\Connector\Livewire\Reconciliation\Index;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\UnknownOutcomeReporter;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use Illuminate\Support\Collection;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function unknownOutcomeAllowingAuthz(): void
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

function unknownOutcomeDenyingAuthz(): void
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
function unknownOutcomeCaptureActions(array &$recorded): void
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

function unknownOutcomeOperatorConnection(int $tenantId, ?int $companyId): int
{
    app(TenantContext::class)->set($tenantId);
    $scope = $companyId === null ? ProviderScope::tenant() : ProviderScope::company($companyId);
    $connection = app(ProviderConnectionStore::class)->configure($scope, 'test.unknown-outcome');

    return (int) app(ProviderConnectionStore::class)->activate((int) $connection->id)->id;
}

function unknownOutcomeOperatorIssue(int $connectionId, string $idempotencyKey): ReconciliationIssue
{
    return app(UnknownOutcomeReporter::class)->record(
        $connectionId,
        CommandOutcome::unknown($idempotencyKey),
    );
}

test('the reconciliation queue lists an unknown command outcome awaiting confirmation', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Unknown Outcome Queue Tenant']);
    $connectionId = unknownOutcomeOperatorConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    unknownOutcomeAllowingAuthz();
    unknownOutcomeOperatorIssue($connectionId, 'cmd-queue-1');

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->assertSee('cmd-queue-1')
        ->assertSee(__('Unconfirmed command outcome'));
});

test('an attributed operator confirms a delivered command and the issue resolves without a resend', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Unknown Outcome Confirm Tenant']);
    $connectionId = unknownOutcomeOperatorConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    unknownOutcomeAllowingAuthz();
    $recorded = [];
    unknownOutcomeCaptureActions($recorded);
    $issue = unknownOutcomeOperatorIssue($connectionId, 'cmd-confirm-1');

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('commandResolutions.'.$issue->id, CommandResolution::ConfirmedDelivered->value)
        ->set('reviewReferences.'.$issue->id, 'review-2026-09-06')
        ->call('confirmUnknownOutcome', (int) $issue->id)
        ->assertHasNoErrors();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_RESOLVED)
        ->and($issue->details['reason_code'] ?? null)->toBe(CommandResolution::ConfirmedDelivered->value)
        ->and($recorded)->toContain([
            'event' => 'people_connector.reconciliation.command_outcome_confirmed',
            'subject' => ['name' => 'reconciliation_issue', 'id' => $issue->id, 'identifier' => $issue->issue_key],
            'surface' => 'admin.people-connector.reconciliation.index',
            'context' => [
                'review_reference' => 'review-2026-09-06',
                'resolution' => CommandResolution::ConfirmedDelivered->value,
            ],
        ]);
});

test('an operator whose capability is revoked cannot confirm an unknown command outcome', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Unknown Outcome Denial Tenant']);
    $connectionId = unknownOutcomeOperatorConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    unknownOutcomeAllowingAuthz();
    $issue = unknownOutcomeOperatorIssue($connectionId, 'cmd-denied-1');

    $component = Livewire::actingAs($user)->test(Index::class, ['connectionId' => $connectionId]);

    // The capability is withdrawn after the queue is on screen. The action must
    // re-authorize; a guard that only runs at mount would let this through.
    unknownOutcomeDenyingAuthz();

    $component
        ->set('commandResolutions.'.$issue->id, CommandResolution::ConfirmedDelivered->value)
        ->set('reviewReferences.'.$issue->id, 'review-2026-09-06')
        ->call('confirmUnknownOutcome', (int) $issue->id)
        ->assertForbidden();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('a sibling-company operator cannot confirm an unknown command outcome on another company connection', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $alphaConnectionId = unknownOutcomeOperatorConnection($fixture->tenantId, (int) $fixture->alphaCompany->id);
    $betaUser = User::factory()->create(['company_id' => $fixture->betaCompany->id]);
    unknownOutcomeAllowingAuthz();
    $issue = unknownOutcomeOperatorIssue($alphaConnectionId, 'cmd-cross-company-1');

    Livewire::actingAs($betaUser)
        ->test(Index::class, ['connectionId' => $alphaConnectionId])
        ->assertForbidden();

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('an operator cannot confirm an unknown command outcome that belongs to another connection', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    $alphaConnectionId = unknownOutcomeOperatorConnection($fixture->tenantId, (int) $fixture->alphaCompany->id);
    $betaConnectionId = unknownOutcomeOperatorConnection($fixture->tenantId, (int) $fixture->betaCompany->id);
    $betaUser = User::factory()->create(['company_id' => $fixture->betaCompany->id]);
    unknownOutcomeAllowingAuthz();
    $alphaIssue = unknownOutcomeOperatorIssue($alphaConnectionId, 'cmd-foreign-issue-1');

    Livewire::actingAs($betaUser)
        ->test(Index::class, ['connectionId' => $betaConnectionId])
        ->set('commandResolutions.'.$alphaIssue->id, CommandResolution::ConfirmedDelivered->value)
        ->set('reviewReferences.'.$alphaIssue->id, 'review-2026-09-06')
        ->call('confirmUnknownOutcome', (int) $alphaIssue->id)
        ->assertNotFound();

    expect($alphaIssue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});

test('a confirmation requires a resolution the operator actually chose', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Unknown Outcome Validation Tenant']);
    $connectionId = unknownOutcomeOperatorConnection((int) $tenant->id, (int) $company->id);
    $user = User::factory()->create(['company_id' => $company->id]);
    unknownOutcomeAllowingAuthz();
    $issue = unknownOutcomeOperatorIssue($connectionId, 'cmd-validate-1');

    Livewire::actingAs($user)
        ->test(Index::class, ['connectionId' => $connectionId])
        ->set('commandResolutions.'.$issue->id, 'confirmed_whatever')
        ->set('reviewReferences.'.$issue->id, 'review-2026-09-06')
        ->call('confirmUnknownOutcome', (int) $issue->id)
        ->assertHasErrors('commandResolutions.'.$issue->id);

    expect($issue->refresh()->status)->toBe(ReconciliationIssue::STATUS_OPEN);
});
