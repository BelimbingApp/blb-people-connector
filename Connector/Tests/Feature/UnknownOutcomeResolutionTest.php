<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesProviderCommands;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Enums\CommandResolution;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationReviewService;
use App\Domains\PeopleConnector\Connector\Services\UnknownOutcomeReporter;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;

function unknownOutcomeIssue(string $key = 'idem-90'): array
{
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $connectionId = (int) ProviderConnection::query()->forTenant($fixture->tenantId)->value('id');
    $issue = app(UnknownOutcomeReporter::class)->record($connectionId, CommandOutcome::unknown($key));

    return [$fixture->tenantId, $connectionId, $issue];
}

test('an operator can confirm the command was delivered, closing the issue', function (): void {
    [, $connectionId, $issue] = unknownOutcomeIssue();

    $resolved = app(ReconciliationReviewService::class)->confirmCommandOutcome(
        $connectionId,
        (int) $issue->id,
        CommandResolution::ConfirmedDelivered,
        'review:ticket-1',
        new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    );

    expect($resolved->resolved_at)->not->toBeNull()
        ->and($resolved->details['reason_code'] ?? null)->toBe(CommandResolution::ConfirmedDelivered->value);
});

test('confirming an outcome never sends the command again', function (): void {
    [, $connectionId, $issue] = unknownOutcomeIssue('idem-91');
    $asked = [];
    // A resolution is an operator recording what they found out of band. If it
    // could trigger a resend, an operator closing their queue would be the
    // duplicate-execution path #138 exists to close.
    app()->instance(ReconcilesProviderCommands::class, new class($asked) implements ReconcilesProviderCommands
    {
        public function __construct(public array &$asked) {}

        public function findCommand(string $idempotencyKey): ?CommandOutcome
        {
            $this->asked[] = $idempotencyKey;

            return null;
        }
    });

    app(ReconciliationReviewService::class)->confirmCommandOutcome(
        $connectionId,
        (int) $issue->id,
        CommandResolution::ConfirmedNotDelivered,
        'review:ticket-2',
        new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    );

    expect($asked)->toBe([]);
});

test('confirming refuses an issue that is not an unknown-outcome issue', function (): void {
    [, $connectionId] = unknownOutcomeIssue('idem-92');
    $other = app(ReconciliationIssueStore::class)
        ->report($connectionId, 'other-key', 'sync_conflict');

    expect(fn () => app(ReconciliationReviewService::class)->confirmCommandOutcome(
        $connectionId,
        (int) $other->id,
        CommandResolution::ConfirmedDelivered,
        'review:ticket-3',
        new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    ))->toThrow(InvalidReconciliationIssueException::class);
});

test('confirming requires a review reference so the decision is attributable', function (): void {
    [, $connectionId, $issue] = unknownOutcomeIssue('idem-93');

    expect(fn () => app(ReconciliationReviewService::class)->confirmCommandOutcome(
        $connectionId,
        (int) $issue->id,
        CommandResolution::ConfirmedDelivered,
        '  ',
        new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    ))->toThrow(InvalidReconciliationIssueException::class);
});

test('an issue belonging to another connection is refused', function (): void {
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $connectionId = (int) ProviderConnection::query()->forTenant($fixture->tenantId)->value('id');
    $issue = app(UnknownOutcomeReporter::class)->record($connectionId, CommandOutcome::unknown('idem-94'));
    // The fixture provisions one connection per company. The issue is scoped
    // to the one that reported it, so its sibling must not be able to close it.
    $otherConnectionId = (int) ProviderConnection::query()
        ->forTenant($fixture->tenantId)
        ->whereKeyNot($connectionId)
        ->value('id');

    expect(fn () => app(ReconciliationReviewService::class)->confirmCommandOutcome(
        $otherConnectionId,
        (int) $issue->id,
        CommandResolution::ConfirmedDelivered,
        'review:ticket-4',
        new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
    ))->toThrow(ConnectorRecordNotFoundException::class);
});
