<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;
use App\Domains\PeopleConnector\Connector\Http\Controllers\DelegatedCommandController;
use App\Domains\PeopleConnector\Connector\Services\DelegatedAuthoritySigner;
use Illuminate\Http\Request;

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

    // One minute past expiry: outside the default 30-second skew tolerance
    // (#185). The exact boundary is DelegatedAuthorityHardeningTest's.
    expect(fn () => $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-06T12:03:00+00:00')))
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

/*
 * Rotation with an overlap window (#262). No clock is read anywhere below:
 * every instant is passed to verify(), so no second can tick between minting
 * a token and asking about it, and each boundary is asserted from both sides
 * of the same instant.
 */

test('a token signed under the rotated-away secret verifies inside the overlap and is refused at its expiry instant and after', function (): void {
    $previous = str_repeat('a', 64);
    config()->set('people-connector.delegation.secret', $previous);
    $signer = app(DelegatedAuthoritySigner::class);

    // Minted before the rotation. Nothing about this token changes when the
    // deployment's keys do; the whole point of the window is that it survives.
    $token = $signer->sign(delegationAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-07T12:00:00+00:00'),
        'expiresAt' => new DateTimeImmutable('2026-09-07T12:05:00+00:00'),
    ]));

    config()->set('people-connector.delegation.secret', str_repeat('b', 64));
    config()->set('people-connector.delegation.previous_secret', $previous);
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    expect($signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:01:59+00:00'))->subject)
        ->toBe('employee:EMP-1');

    // The expiry instant is when the overlap is over, not the last moment it
    // holds. The token itself is still live until 12:05, so an Expired answer
    // here would be the wrong refusal for the right reason.
    foreach (['2026-09-07T12:02:00+00:00', '2026-09-07T12:02:01+00:00'] as $lapsed) {
        try {
            $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable($lapsed));
            test()->fail("A token signed with the retired secret was accepted at {$lapsed}.");
        } catch (DelegatedAuthorityException $refused) {
            expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::Unsigned);
        }
    }
});

test('a previous delegation secret with no expiry, an unreadable expiry or one already past is never consulted', function (): void {
    $previous = str_repeat('a', 64);
    config()->set('people-connector.delegation.secret', $previous);
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-07T12:00:00+00:00'),
        'expiresAt' => new DateTimeImmutable('2026-09-07T12:05:00+00:00'),
    ]));
    config()->set('people-connector.delegation.secret', str_repeat('b', 64));
    config()->set('people-connector.delegation.previous_secret', $previous);

    // An old key with no end date is a key nobody retired, and a window that
    // has closed is not a window. Neither is a reason to trust a signature.
    foreach ([null, '', 'whenever', '2026-09-07T11:59:59+00:00'] as $expiry) {
        config()->set('people-connector.delegation.previous_secret_expires_at', $expiry);
        try {
            $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:01:00+00:00'));
            test()->fail('A retired secret with expiry ['.var_export($expiry, true).'] was consulted.');
        } catch (DelegatedAuthorityException $refused) {
            expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::Unsigned);
        }
    }

    // A key too weak to sign with is too weak to accept. sign() will not use a
    // short key either, so this signature is minted by hand: without it the
    // case would pass because the payload was signed by something else.
    $short = str_repeat('s', 16);
    $payload = explode('.', $token, 2)[0];
    $forged = $payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $short, true)), '+/', '-_'), '=');
    config()->set('people-connector.delegation.previous_secret', $short);
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    expect(fn () => $signer->verify($forged, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:01:00+00:00')))
        ->toThrow(DelegatedAuthorityException::class);
});

test('a token signed with a secret that is neither current nor previous is refused inside the overlap', function (): void {
    config()->set('people-connector.delegation.secret', str_repeat('c', 64));
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-07T12:00:00+00:00'),
        'expiresAt' => new DateTimeImmutable('2026-09-07T12:05:00+00:00'),
    ]));

    config()->set('people-connector.delegation.secret', str_repeat('b', 64));
    config()->set('people-connector.delegation.previous_secret', str_repeat('a', 64));
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    // An open window admits one named key, not any key.
    try {
        $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:01:00+00:00'));
        test()->fail('A token signed with an unknown secret was accepted during the overlap.');
    } catch (DelegatedAuthorityException $refused) {
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::Unsigned);
    }
});

test('a token signed under the previous secret for another audience is still refused as a wrong audience', function (): void {
    $previous = str_repeat('a', 64);
    config()->set('people-connector.delegation.secret', $previous);
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority([
        'audience' => 'people-connector.somewhere-else',
        'issuedAt' => new DateTimeImmutable('2026-09-07T12:00:00+00:00'),
        'expiresAt' => new DateTimeImmutable('2026-09-07T12:05:00+00:00'),
    ]));

    config()->set('people-connector.delegation.secret', str_repeat('b', 64));
    config()->set('people-connector.delegation.previous_secret', $previous);
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    // The overlap moves one check, not the rest of them: a token the old key
    // vouches for still has to be addressed here.
    try {
        $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:01:00+00:00'));
        test()->fail('An authority for another audience was accepted under the previous secret.');
    } catch (DelegatedAuthorityException $refused) {
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::WrongAudience);
    }
});

test('a refusal after the overlap has closed repeats neither the current nor the retired secret', function (): void {
    $previous = str_repeat('a', 64);
    $current = str_repeat('b', 64);
    config()->set('people-connector.delegation.secret', $previous);
    $signer = app(DelegatedAuthoritySigner::class);
    $token = $signer->sign(delegationAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-07T12:00:00+00:00'),
        'expiresAt' => new DateTimeImmutable('2026-09-07T12:05:00+00:00'),
    ]));

    config()->set('people-connector.delegation.secret', $current);
    config()->set('people-connector.delegation.previous_secret', $previous);
    config()->set('people-connector.delegation.previous_secret_expires_at', '2026-09-07T12:02:00+00:00');

    try {
        $signer->verify($token, DELEGATION_AUDIENCE, new DateTimeImmutable('2026-09-07T12:03:00+00:00'));
        test()->fail('A token signed with the retired secret was accepted after the overlap.');
    } catch (DelegatedAuthorityException $refused) {
        // A rotated-away key must not be recoverable from what the refusal
        // says: docs/contracts/diagnostic-privacy.md. One needle per negated
        // assertion, because not->toContain(a, b) only fails on both.
        expect($refused->getMessage())->not->toContain($previous);
        expect($refused->getMessage())->not->toContain($current);
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::Unsigned);
    }
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

/**
 * Run one authority down both paths and report what each decided.
 *
 * The point of the boundary is that these two answers are always the same, so
 * the fixture asks them the same question rather than two similar ones.
 *
 * @return array{inProcess: bool, http: bool}
 */
function delegationBothPaths(DelegatedAuthority $authority, string $audience, string $operation): array
{
    $signer = app(DelegatedAuthoritySigner::class);
    $port = app(AcceptsDelegatedCommands::class);

    $inProcess = true;
    try {
        $port->accept($authority, $operation);
    } catch (DelegatedAuthorityException) {
        $inProcess = false;
    }

    // Same claims, its own jti: the in-process spend above consumed the
    // first token, and a replay refusal here would be the ledger talking,
    // not the transport under comparison (#185).
    $twin = DelegatedAuthority::fromClaims([...$authority->claims(), 'jti' => $authority->id.'-http']);
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, $signer->sign($twin));
    $response = app(DelegatedCommandController::class)($request, $audience, $operation);

    return ['inProcess' => $inProcess, 'http' => $response->getStatusCode() === 200];
}

test('an accepted authority is accepted identically in process and over http', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);

    $decisions = delegationBothPaths(delegationAuthority([
        'issuedAt' => new DateTimeImmutable,
        'expiresAt' => (new DateTimeImmutable)->modify('+2 minutes'),
    ]), DELEGATION_AUDIENCE, 'employee.command.submit');

    expect($decisions['inProcess'])->toBeTrue()
        ->and($decisions['http'])->toBeTrue();
});

test('a wrong-tenant authority is refused identically in process and over http', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);

    // The denial fixture the acceptance asks for: one authority, both paths,
    // same answer. A transport that decided this differently would be a way
    // around the backend recheck, which is the whole thing being guarded.
    $decisions = delegationBothPaths(delegationAuthority([
        'tenantId' => 42,
        'issuedAt' => new DateTimeImmutable,
        'expiresAt' => (new DateTimeImmutable)->modify('+2 minutes'),
    ]), DELEGATION_AUDIENCE, 'employee.command.submit');

    expect($decisions['inProcess'])->toBeFalse()
        ->and($decisions['http'])->toBeFalse();
});

test('a wrong-operation authority is refused identically in process and over http', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);

    $decisions = delegationBothPaths(delegationAuthority([
        'issuedAt' => new DateTimeImmutable,
        'expiresAt' => (new DateTimeImmutable)->modify('+2 minutes'),
    ]), DELEGATION_AUDIENCE, 'employee.command.cancel');

    expect($decisions['inProcess'])->toBeFalse()
        ->and($decisions['http'])->toBeFalse();
});

test('the http path refuses a token this connector did not sign', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, 'forged.payload');

    $response = app(DelegatedCommandController::class)($request, DELEGATION_AUDIENCE, 'employee.command.submit');

    // Verification is the HTTP path's own job — an in-process caller never had
    // a token to check — and it happens before the port is reached at all.
    expect($response->getStatusCode())->toBe(403);
});

test('the http refusal names a reason code and never the refusal message', function (): void {
    delegationSecret();
    app(TenantContext::class)->set(41);
    $signer = app(DelegatedAuthoritySigner::class);
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, $signer->sign(delegationAuthority([
        'tenantId' => 42,
        'issuedAt' => new DateTimeImmutable,
        'expiresAt' => (new DateTimeImmutable)->modify('+2 minutes'),
    ])));

    $response = app(DelegatedCommandController::class)($request, DELEGATION_AUDIENCE, 'employee.command.submit');

    // Refusal messages name tenants and operations. Reason codes, not prose:
    // docs/contracts/diagnostic-privacy.md.
    //
    // The code became specific in #177, because denial parity cannot be proved
    // against a single code that every refusal shares. What the body still must
    // not carry is the value that was refused, which is what the second
    // assertion holds on to.
    expect($response->getData(true))->toBe(['refused' => DelegatedAuthorityRefusal::WrongTenant->value])
        ->and($response->getContent())->not->toContain('42');
});

test('the in-process port enforces tenant and expiry for every command type', function (string $operation): void {
    delegationSecret();
    app(TenantContext::class)->set(41);
    $port = app(AcceptsDelegatedCommands::class);
    $now = new DateTimeImmutable;

    $accepted = $port->accept(delegationAuthority([
        'operation' => $operation,
        'issuedAt' => $now->modify('-1 minute'),
        'expiresAt' => $now->modify('+1 minute'),
    ]), $operation);
    expect($accepted->operation)->toBe($operation);

    try {
        $port->accept(delegationAuthority([
            'operation' => $operation,
            'issuedAt' => $now->modify('-10 minutes'),
            'expiresAt' => $now->modify('-5 minutes'),
        ]), $operation);
        test()->fail("An expired {$operation} authority was accepted in process.");
    } catch (DelegatedAuthorityException $refused) {
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::Expired);
    }

    try {
        $port->accept(delegationAuthority([
            'operation' => $operation,
            'tenantId' => 42,
            'issuedAt' => $now->modify('-1 minute'),
            'expiresAt' => $now->modify('+1 minute'),
        ]), $operation);
        test()->fail("A wrong-tenant {$operation} authority was accepted in process.");
    } catch (DelegatedAuthorityException $refused) {
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::WrongTenant);
    }
})->with(['employee.command.submit', 'employee.command.cancel']);
