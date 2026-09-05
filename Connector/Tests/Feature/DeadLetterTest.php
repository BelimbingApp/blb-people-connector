<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;

/*
 * Self-contained: every helper is prefixed deadLetter and lives here.
 *
 * These cover the parking contract itself. The runner-driven half — a page
 * refused three times, parked, and the checkpoint advanced past it — arrives
 * with the implementation.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function deadLetterHash(string $payload): string
{
    return hash('sha256', $payload);
}

test('a payload hash is accepted as a hex digest', function (): void {
    $details = new ReconciliationIssueDetails(
        reasonCode: 'projection_conflict',
        payloadHash: deadLetterHash('{"employees":[{"id":"EMP-1"}]}'),
    );

    expect($details->toArray()['payload_hash'])->toBe(deadLetterHash('{"employees":[{"id":"EMP-1"}]}'));
});

test('the payload itself cannot be smuggled into the hash field', function (): void {
    // The whole reason this field exists is to identify a page without keeping
    // it. A hash field that accepted arbitrary text would be the generic
    // payload slot docs/contracts/diagnostic-privacy.md says this DTO must not
    // have, and nobody would notice until a provider payload was sitting in an
    // operator's queue.
    expect(fn () => new ReconciliationIssueDetails(payloadHash: '{"employees":[{"id":"EMP-1","email":"ada@example.test"}]}'))
        ->toThrow(InvalidReconciliationIssueException::class);
});

test('a hash of the wrong shape is refused rather than stored', function (): void {
    foreach (['', 'deadbeef', str_repeat('z', 64), strtoupper(deadLetterHash('x')), deadLetterHash('x').'0'] as $candidate) {
        expect(fn () => new ReconciliationIssueDetails(payloadHash: $candidate))
            ->toThrow(InvalidReconciliationIssueException::class);
    }
});

test('a parked page carries a reason code and never an exception message', function (): void {
    // docs/contracts/diagnostic-privacy.md: conflict handling maps exception
    // classes to reason codes rather than persisting getMessage(). "The last
    // error" on a dead letter is therefore a code, not prose.
    expect(fn () => new ReconciliationIssueDetails(reasonCode: 'Connection refused by provider at 10.0.0.4'))
        ->toThrow(InvalidReconciliationIssueException::class);

    $details = new ReconciliationIssueDetails(reasonCode: 'projection_conflict', payloadHash: deadLetterHash('page'));

    expect($details->toArray())->toBe([
        'reason_code' => 'projection_conflict',
        'payload_hash' => deadLetterHash('page'),
    ]);
});
