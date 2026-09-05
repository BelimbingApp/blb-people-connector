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

    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, $signer->sign($authority));
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
