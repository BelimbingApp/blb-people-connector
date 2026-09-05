<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Enums\CommandFailureReason;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\UnknownOutcomeReporter;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;

function unknownOutcomeConnection(): array
{
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $connectionId = (int) ProviderConnection::query()
        ->forTenant($fixture->tenantId)->value('id');

    return [$fixture->tenantId, $connectionId];
}

test('an outcome still unknown after reconciliation becomes an operator issue keyed by the command', function (): void {
    [$tenantId, $connectionId] = unknownOutcomeConnection();

    $issue = app(UnknownOutcomeReporter::class)->record(
        $connectionId,
        CommandOutcome::unknown('idem-77'),
    );

    // The key is the whole point: an operator resolving this must be able to
    // ask the provider about the same command the connector asked about.
    expect($issue->issue_key)->toBe('idem-77')
        ->and($issue->kind)->toBe('sync_unknown_outcome')
        ->and($issue->tenant_id)->toBe($tenantId)
        ->and($issue->details['reason_code'] ?? null)->toBe(CommandFailureReason::AnswerLost->value)
        ->and($issue->resolved_at)->toBeNull();
});

test('the adapter answer recorded is a connector-owned code, never adapter text', function (): void {
    [, $connectionId] = unknownOutcomeConnection();

    $issue = app(UnknownOutcomeReporter::class)->record(
        $connectionId,
        CommandOutcome::unknown('idem-78'),
    );

    // docs/contracts/diagnostic-privacy.md: reason codes, not getMessage().
    $reason = $issue->details['reason_code'] ?? null;

    expect(CommandFailureReason::tryFrom((string) $reason))->not->toBeNull();
});

test('a settled outcome is never recorded as an unknown-outcome issue', function (): void {
    [, $connectionId] = unknownOutcomeConnection();
    $reporter = app(UnknownOutcomeReporter::class);

    foreach ([
        CommandOutcome::deliveredAccepted('idem-79', 'ref'),
        CommandOutcome::deliveredRejected('idem-80'),
        CommandOutcome::notDelivered('idem-81'),
    ] as $settled) {
        expect($reporter->record($connectionId, $settled))->toBeNull();
    }

    expect(ReconciliationIssue::query()->where('kind', 'sync_unknown_outcome')->count())->toBe(0);
});

test('reporting the same unknown command twice keeps one open issue', function (): void {
    [, $connectionId] = unknownOutcomeConnection();
    $reporter = app(UnknownOutcomeReporter::class);

    $first = $reporter->record($connectionId, CommandOutcome::unknown('idem-82'));
    $second = $reporter->record($connectionId, CommandOutcome::unknown('idem-82'));

    expect($second->id)->toBe($first->id)
        ->and(ReconciliationIssue::query()->where('issue_key', 'idem-82')->count())->toBe(1);
});
