<?php

use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Enums\CommandFailureReason;
use App\Domains\PeopleConnector\Connector\Enums\CommandOutcomeState;

test('a delivered command records which way the provider answered', function (): void {
    $accepted = CommandOutcome::deliveredAccepted('idem-1', 'provider-ref-9');
    $rejected = CommandOutcome::deliveredRejected('idem-2');

    expect($accepted->state)->toBe(CommandOutcomeState::DeliveredAccepted)
        ->and($accepted->idempotencyKey)->toBe('idem-1')
        ->and($accepted->providerReference)->toBe('provider-ref-9')
        ->and($accepted->isSettled())->toBeTrue()
        ->and($accepted->mayRetry())->toBeFalse()
        ->and($rejected->state)->toBe(CommandOutcomeState::DeliveredRejected)
        ->and($rejected->reason)->toBe(CommandFailureReason::ProviderRefused)
        ->and($rejected->isSettled())->toBeTrue()
        ->and($rejected->mayRetry())->toBeFalse();
});

test('a command that never left is the only outcome a caller may retry blind', function (): void {
    $notDelivered = CommandOutcome::notDelivered('idem-3');

    expect($notDelivered->state)->toBe(CommandOutcomeState::NotDelivered)
        ->and($notDelivered->isSettled())->toBeTrue()
        ->and($notDelivered->mayRetry())->toBeTrue();
});

test('an unknown outcome is neither settled nor retryable', function (): void {
    // The rule this contract exists for: a timeout after delivery is not proof
    // of failure. Retrying it blind is how a provider ends up with two of the
    // same command.
    $unknown = CommandOutcome::unknown('idem-4');

    expect($unknown->state)->toBe(CommandOutcomeState::Unknown)
        ->and($unknown->isSettled())->toBeFalse()
        ->and($unknown->mayRetry())->toBeFalse()
        ->and($unknown->requiresReconciliation())->toBeTrue();
});

test('only an unknown outcome requires reconciliation', function (): void {
    foreach ([
        CommandOutcome::deliveredAccepted('k', 'ref'),
        CommandOutcome::deliveredRejected('k'),
        CommandOutcome::notDelivered('k'),
    ] as $settled) {
        expect($settled->requiresReconciliation())->toBeFalse();
    }
});

test('an outcome cannot be built without the idempotency key reconciliation needs', function (): void {
    expect(fn () => CommandOutcome::unknown('  '))
        ->toThrow(InvalidArgumentException::class, 'idempotency key');
});

test('an outcome carries no free-text reason an adapter message could ride in on', function (): void {
    // docs/contracts/diagnostic-privacy.md: reason codes, not getMessage().
    $reason = new ReflectionProperty(CommandOutcome::class, 'reason');

    expect((string) $reason->getType())->toBe('?'.CommandFailureReason::class)
        ->and(CommandOutcome::unknown('k')->reason)->toBe(CommandFailureReason::AnswerLost)
        ->and(CommandOutcome::notDelivered('k')->reason)->toBe(CommandFailureReason::NotSent);
});

test('a timeout after delivery maps to unknown, not to failure', function (): void {
    expect(CommandOutcomeState::fromTransportFailure(delivered: true))->toBe(CommandOutcomeState::Unknown)
        ->and(CommandOutcomeState::fromTransportFailure(delivered: false))->toBe(CommandOutcomeState::NotDelivered);
});
