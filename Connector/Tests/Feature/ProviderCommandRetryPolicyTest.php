<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CommandOutcome;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Services\ProviderCommandRetryPolicy;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;

function retryPolicyConnection(): array
{
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $connectionId = (int) ProviderConnection::query()->forTenant($fixture->tenantId)->value('id');

    return [$fixture->tenantId, $connectionId];
}

test('retryable command outcomes stop at the configured limit and park the idempotency key', function (): void {
    [, $connectionId] = retryPolicyConnection();
    config()->set('people-connector.command_reconciliation.max_attempts', 3);
    config()->set('people-connector.command_reconciliation.backoff_seconds', 45);
    $policy = app(ProviderCommandRetryPolicy::class);

    $first = $policy->decide($connectionId, CommandOutcome::notDelivered('idem-1009'), 1);
    $second = $policy->decide($connectionId, CommandOutcome::notDelivered('idem-1009'), $first->nextAttempt);
    $parked = $policy->decide($connectionId, CommandOutcome::notDelivered('idem-1009'), $second->nextAttempt);

    expect($first->retry)->toBeTrue()
        ->and($first->nextAttempt)->toBe(2)
        ->and($first->backoffSeconds)->toBe(45)
        ->and($second->retry)->toBeTrue()
        ->and($second->nextAttempt)->toBe(3)
        ->and($parked->retry)->toBeFalse()
        ->and($parked->isParked())->toBeTrue()
        ->and($parked->issue?->issue_key)->toBe('idem-1009')
        ->and($parked->issue?->kind)->toBe('sync_unknown_outcome');
});

test('a settled delivered outcome never enters the retry budget', function (): void {
    [, $connectionId] = retryPolicyConnection();

    $decision = app(ProviderCommandRetryPolicy::class)->decide(
        $connectionId,
        CommandOutcome::deliveredAccepted('idem-1009-settled', 'provider-ref'),
        1,
    );

    expect($decision->retry)->toBeFalse()
        ->and($decision->nextAttempt)->toBe(1)
        ->and($decision->backoffSeconds)->toBe(0)
        ->and($decision->isParked())->toBeFalse();
});

test('a retry attempt must be positive', function (): void {
    [, $connectionId] = retryPolicyConnection();

    expect(fn () => app(ProviderCommandRetryPolicy::class)->decide(
        $connectionId,
        CommandOutcome::notDelivered('idem-1009-invalid'),
        0,
    ))->toThrow(InvalidArgumentException::class, 'at least one');
});
