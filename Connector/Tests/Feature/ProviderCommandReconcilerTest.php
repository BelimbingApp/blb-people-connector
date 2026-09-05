<?php

use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesProviderCommands;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Data\Hr2000DeploymentProfile;
use App\Domains\PeopleConnector\Connector\Enums\CommandOutcomeState;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderUnknownOutcomeException;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;
use App\Domains\PeopleConnector\Connector\Services\ProviderCommandReconciler;

/** An adapter that answers whether it already holds a command under a key. */
function reconcilingAdapter(?CommandOutcome $known, ?callable $spy = null): ReconcilesProviderCommands
{
    return new class($known, $spy) implements ReconcilesProviderCommands
    {
        public function __construct(private readonly ?CommandOutcome $known, private $spy) {}

        public function findCommand(string $idempotencyKey): ?CommandOutcome
        {
            if ($this->spy !== null) {
                ($this->spy)($idempotencyKey);
            }

            return $this->known;
        }
    };
}

test('a settled outcome is returned untouched and the adapter is never asked', function (): void {
    $asked = [];
    $adapter = reconcilingAdapter(null, function (string $key) use (&$asked): void {
        $asked[] = $key;
    });

    foreach ([
        CommandOutcome::deliveredAccepted('k1', 'ref'),
        CommandOutcome::deliveredRejected('k2'),
        CommandOutcome::notDelivered('k3'),
    ] as $settled) {
        expect(app(ProviderCommandReconciler::class)->settle($settled, $adapter))->toBe($settled);
    }

    expect($asked)->toBe([]);
});

test('an unknown outcome is settled from what the provider already holds, not retried', function (): void {
    $asked = [];
    $adapter = reconcilingAdapter(
        CommandOutcome::deliveredAccepted('idem-7', 'provider-ref-42'),
        function (string $key) use (&$asked): void {
            $asked[] = $key;
        },
    );

    $settled = app(ProviderCommandReconciler::class)
        ->settle(CommandOutcome::unknown('idem-7'), $adapter);

    // Duplicate suppression: the command was already there under this key, so
    // the caller learns it succeeded instead of sending a second one.
    expect($asked)->toBe(['idem-7'])
        ->and($settled->state)->toBe(CommandOutcomeState::DeliveredAccepted)
        ->and($settled->providerReference)->toBe('provider-ref-42')
        ->and($settled->mayRetry())->toBeFalse();
});

test('an unknown outcome the provider never received becomes retryable', function (): void {
    $settled = app(ProviderCommandReconciler::class)
        ->settle(CommandOutcome::unknown('idem-8'), reconcilingAdapter(null));

    expect($settled->state)->toBe(CommandOutcomeState::NotDelivered)
        ->and($settled->mayRetry())->toBeTrue()
        ->and($settled->idempotencyKey)->toBe('idem-8');
});

test('an unknown outcome is never settled by an adapter that cannot reconcile', function (): void {
    // HR2000 declares no operations, so it cannot answer whether a command
    // exists. Guessing "not delivered" here is exactly the blind retry this
    // contract exists to prevent.
    expect(fn () => app(ProviderCommandReconciler::class)
        ->settle(CommandOutcome::unknown('idem-9'), new Hr2000Adapter(Hr2000DeploymentProfile::undiscovered())))
        ->toThrow(ProviderUnknownOutcomeException::class);
});
