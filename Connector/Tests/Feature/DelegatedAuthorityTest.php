<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;
use App\Domains\PeopleConnector\Connector\Services\DelegatedAuthoritySigner;

/*
 * Self-contained: every helper is prefixed delegation and lives here.
 *
 * This is the boundary only. There is no leave or attendance behaviour behind
 * it and none is asserted here.
 */

const DELEGATION_AUDIENCE = 'people-connector.first-party';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function delegationSecret(): void
{
    config()->set('people-connector.delegation.secret', str_repeat('k', 64));
}

function delegationAuthority(array $overrides = []): DelegatedAuthority
{
    return new DelegatedAuthority(
        subject: $overrides['subject'] ?? 'employee:EMP-1',
        tenantId: $overrides['tenantId'] ?? 41,
        companyId: $overrides['companyId'] ?? 7,
        operation: $overrides['operation'] ?? 'employee.command.submit',
        audience: $overrides['audience'] ?? DELEGATION_AUDIENCE,
        issuedAt: $overrides['issuedAt'] ?? new DateTimeImmutable('2026-09-06T12:00:00+00:00'),
        expiresAt: $overrides['expiresAt'] ?? new DateTimeImmutable('2026-09-06T12:02:00+00:00'),
    );
}

test('a signed authority verifies back to the same claims', function (): void {
    delegationSecret();
    $signer = app(DelegatedAuthoritySigner::class);
    $authority = delegationAuthority();

    $verified = $signer->verify(
        $signer->sign($authority),
        DELEGATION_AUDIENCE,
        new DateTimeImmutable('2026-09-06T12:01:00+00:00'),
    );

    expect($verified->subject)->toBe('employee:EMP-1')
        ->and($verified->tenantId)->toBe(41)
        ->and($verified->companyId)->toBe(7)
        ->and($verified->operation)->toBe('employee.command.submit')
        ->and($verified->audience)->toBe(DELEGATION_AUDIENCE);
});

test('an expired authority is refused', function (): void {
    delegationSecret();
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority());

    expect(fn () => $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-06T12:02:01+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('an authority for another audience is refused', function (): void {
    delegationSecret();
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority(['audience' => 'people-connector.somewhere-else']));

    // Audience binding is what stops a token minted for one service being
    // replayed against another that trusts the same key.
    expect(fn () => $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-06T12:01:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('a tampered authority is refused', function (): void {
    delegationSecret();
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority());
    [$payload, $signature] = explode('.', $token, 2);
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/'), true), true);
    $claims['company_id'] = 999;
    $forged = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;

    expect(fn () => $signer->verify($forged, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-06T12:01:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('a signing secret that is missing or too short fails closed', function (): void {
    $signer = app(DelegatedAuthoritySigner::class);

    config()->set('people-connector.delegation.secret', null);
    expect(fn () => $signer->sign(delegationAuthority()))->toThrow(DelegatedAuthorityException::class);

    // A short key is worse than no key: it looks configured.
    config()->set('people-connector.delegation.secret', 'short');
    expect(fn () => $signer->sign(delegationAuthority()))->toThrow(DelegatedAuthorityException::class);
});

test('an authority for another tenant is refused by the backend recheck', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);
    $authority = delegationAuthority(['tenantId' => 42]);

    // The signature only proves the claims were not altered. Whether this
    // tenant may be acted on is the backend's question, and it must be asked
    // whatever transport carried the token here.
    expect(fn () => $authority->assertUsableBy(41, 'employee.command.submit', new DateTimeImmutable('2026-09-06T12:01:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('an authority for another operation is refused by the backend recheck', function (): void {
    delegationSecret();
    $authority = delegationAuthority();

    expect(fn () => $authority->assertUsableBy(41, 'employee.command.cancel', new DateTimeImmutable('2026-09-06T12:01:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('an expired authority is refused by the backend recheck even after a valid signature', function (): void {
    delegationSecret();
    $authority = delegationAuthority();

    // Verification and the recheck both look at expiry on purpose. A caller
    // that verified a token minutes ago must not be able to spend it now.
    expect(fn () => $authority->assertUsableBy(41, 'employee.command.submit', new DateTimeImmutable('2026-09-06T12:05:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('an authority whose lifetime exceeds the configured maximum is refused at signing', function (): void {
    delegationSecret();
    config()->set('people-connector.delegation.max_lifetime_seconds', 120);
    $signer = app(DelegatedAuthoritySigner::class);

    // Short-lived is a property of the boundary, not a convention callers are
    // trusted to follow.
    expect(fn () => $signer->sign(delegationAuthority([
        'expiresAt' => new DateTimeImmutable('2026-09-06T13:00:00+00:00'),
    ])))->toThrow(DelegatedAuthorityException::class);
});
