<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;
use App\Domains\PeopleConnector\Connector\Http\Controllers\DelegatedCommandController;
use App\Domains\PeopleConnector\Connector\Services\DelegatedAuthoritySigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed hardening and lives here (#185).
 *
 * #182 proved the signature, audience, expiry, tenant and operation checks one
 * at a time. This file covers what it said it did not: a token spent twice,
 * the clock-skew boundary on both ends of the lifetime, the audience being
 * asked in process and not only at the wire, and another tenant's subject
 * presented while the right tenant is current.
 */

const HARDENING_AUDIENCE = 'people-connector.first-party';

const HARDENING_TENANT = 41;

const HARDENING_OPERATION = 'employee.command.submit';

const HARDENING_SPENDS = 'people_connector_connector_delegated_spends';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function hardeningReady(int $skewSeconds = 30): void
{
    config()->set('people-connector.delegation.secret', str_repeat('k', 64));
    config()->set('people-connector.delegation.clock_skew_seconds', $skewSeconds);
    app(TenantContext::class)->set(HARDENING_TENANT);
}

function hardeningAuthority(array $overrides = []): DelegatedAuthority
{
    $now = new DateTimeImmutable;

    return new DelegatedAuthority(
        subject: $overrides['subject'] ?? 'employee:EMP-1',
        tenantId: $overrides['tenantId'] ?? HARDENING_TENANT,
        companyId: $overrides['companyId'] ?? 7,
        operation: $overrides['operation'] ?? HARDENING_OPERATION,
        audience: $overrides['audience'] ?? HARDENING_AUDIENCE,
        issuedAt: $overrides['issuedAt'] ?? $now,
        expiresAt: $overrides['expiresAt'] ?? $now->modify('+2 minutes'),
        id: $overrides['id'] ?? null,
    );
}

function hardeningRefusalInProcess(DelegatedAuthority $authority, string $operation = HARDENING_OPERATION): ?DelegatedAuthorityRefusal
{
    try {
        app(AcceptsDelegatedCommands::class)->accept($authority, $operation);
    } catch (DelegatedAuthorityException $refused) {
        return $refused->refusal;
    }

    return null;
}

function hardeningRefusalOverHttp(DelegatedAuthority $authority, string $operation = HARDENING_OPERATION, string $audience = HARDENING_AUDIENCE): ?DelegatedAuthorityRefusal
{
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, app(DelegatedAuthoritySigner::class)->sign($authority));
    $response = app(DelegatedCommandController::class)($request, $audience, $operation);

    return $response->getStatusCode() === 200
        ? null
        : DelegatedAuthorityRefusal::from((string) $response->getData(true)['refused']);
}

test('a token carries a jti that survives the signed round trip', function (): void {
    hardeningReady();
    $signer = app(DelegatedAuthoritySigner::class);
    $authority = hardeningAuthority(['id' => 'jti-round-trip']);

    $verified = $signer->verify($signer->sign($authority), HARDENING_AUDIENCE);

    expect($verified->id)->toBe('jti-round-trip');
});

test('two authorities minted alike still receive distinct ids', function (): void {
    hardeningReady();

    // The id is what makes replay detectable; two mints of the same claims
    // must not collide on it, or the second legitimate token reads as a
    // replay of the first.
    expect(hardeningAuthority()->id)->not->toBe(hardeningAuthority()->id);
});

test('a claim set without a jti is refused', function (): void {
    hardeningReady();
    $claims = hardeningAuthority()->claims();
    unset($claims['jti']);

    expect(fn () => DelegatedAuthority::fromClaims($claims))->toThrow(DelegatedAuthorityException::class);
});

test('an authority spent once in process is refused as replayed the second time', function (): void {
    hardeningReady();
    $authority = hardeningAuthority();

    expect(hardeningRefusalInProcess($authority))->toBeNull()
        ->and(hardeningRefusalInProcess($authority))->toBe(DelegatedAuthorityRefusal::Replayed)
        ->and(DB::table(HARDENING_SPENDS)->where('tenant_id', HARDENING_TENANT)->count())->toBe(1);
});

test('a token spent over http is refused as replayed over http and in process', function (): void {
    hardeningReady();
    $authority = hardeningAuthority();

    // One consumption ledger for both transports. A token spent at the wire
    // and then handed in process, or the other way round, is the same token
    // spent twice; the ledger must not care which door it came through.
    expect(hardeningRefusalOverHttp($authority))->toBeNull()
        ->and(hardeningRefusalOverHttp($authority))->toBe(DelegatedAuthorityRefusal::Replayed)
        ->and(hardeningRefusalInProcess($authority))->toBe(DelegatedAuthorityRefusal::Replayed);
});

test('a refused authority is not recorded as spent', function (): void {
    hardeningReady();

    expect(hardeningRefusalInProcess(hardeningAuthority(['tenantId' => HARDENING_TENANT + 1])))->toBe(DelegatedAuthorityRefusal::WrongTenant)
        ->and(DB::table(HARDENING_SPENDS)->count())->toBe(0);
});

test('the same jti is a different token in another tenant', function (): void {
    hardeningReady();
    $port = app(AcceptsDelegatedCommands::class);
    $port->accept(hardeningAuthority(['id' => 'shared-jti']), HARDENING_OPERATION);

    app(TenantContext::class)->set(HARDENING_TENANT + 1);

    // Ids are minted by callers the ledger does not control. Two tenants
    // choosing the same id must each get their one spend, not share it.
    expect(hardeningRefusalInProcess(hardeningAuthority(['id' => 'shared-jti', 'tenantId' => HARDENING_TENANT + 1])))->toBeNull()
        ->and(DB::table(HARDENING_SPENDS)->where('jti', 'shared-jti')->count())->toBe(2);
});

test('the spend ledger is a connector-owned table under retention', function (): void {
    expect(config('people-connector.retention'))->toHaveKey(HARDENING_SPENDS);
});

test('expiry tolerates clock skew up to the configured bound at verification and at spend', function (): void {
    hardeningReady(30);
    $signer = app(DelegatedAuthoritySigner::class);
    $expiresAt = new DateTimeImmutable('2026-09-06T12:02:00+00:00');
    $authority = hardeningAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-06T12:00:00+00:00'),
        'expiresAt' => $expiresAt,
    ]);
    $token = $signer->sign($authority);

    // Exactly at the bound is still inside it; one second past is not.
    expect($signer->verify($token, HARDENING_AUDIENCE, $expiresAt->modify('+30 seconds'))->id)->toBe($authority->id);
    expect(fn () => $signer->verify($token, HARDENING_AUDIENCE, $expiresAt->modify('+31 seconds')))
        ->toThrow(DelegatedAuthorityException::class, 'expired');

    $authority->assertUsableBy(HARDENING_TENANT, HARDENING_OPERATION, $expiresAt->modify('+30 seconds'));
    expect(fn () => $authority->assertUsableBy(HARDENING_TENANT, HARDENING_OPERATION, $expiresAt->modify('+31 seconds')))
        ->toThrow(DelegatedAuthorityException::class, 'expired');
});

test('a token issued in the future is refused beyond the skew bound and accepted within it', function (): void {
    hardeningReady(30);
    $signer = app(DelegatedAuthoritySigner::class);
    $now = new DateTimeImmutable('2026-09-06T12:00:00+00:00');
    $withinSkew = $signer->sign(hardeningAuthority([
        'issuedAt' => $now->modify('+30 seconds'),
        'expiresAt' => $now->modify('+2 minutes'),
    ]));
    $beyondSkew = $signer->sign(hardeningAuthority([
        'issuedAt' => $now->modify('+31 seconds'),
        'expiresAt' => $now->modify('+2 minutes'),
    ]));

    expect($signer->verify($withinSkew, HARDENING_AUDIENCE, $now)->tenantId)->toBe(HARDENING_TENANT);

    try {
        $signer->verify($beyondSkew, HARDENING_AUDIENCE, $now);
        test()->fail('A token issued beyond the skew bound was accepted.');
    } catch (DelegatedAuthorityException $refused) {
        expect($refused->refusal)->toBe(DelegatedAuthorityRefusal::NotYetValid);
    }
});

test('a zero skew makes the bounds exact again', function (): void {
    hardeningReady(0);
    $signer = app(DelegatedAuthoritySigner::class);
    $expiresAt = new DateTimeImmutable('2026-09-06T12:02:00+00:00');
    $token = $signer->sign(hardeningAuthority([
        'issuedAt' => new DateTimeImmutable('2026-09-06T12:00:00+00:00'),
        'expiresAt' => $expiresAt,
    ]));

    expect($signer->verify($token, HARDENING_AUDIENCE, $expiresAt)->tenantId)->toBe(HARDENING_TENANT);
    expect(fn () => $signer->verify($token, HARDENING_AUDIENCE, $expiresAt->modify('+1 second')))
        ->toThrow(DelegatedAuthorityException::class, 'expired');
});

test('a negative or non-integer skew fails closed', function (): void {
    hardeningReady();
    config()->set('people-connector.delegation.clock_skew_seconds', -1);

    expect(fn () => app(AcceptsDelegatedCommands::class)->accept(hardeningAuthority(), HARDENING_OPERATION))
        ->toThrow(DelegatedAuthorityException::class, 'clock_skew_seconds');
});

test('an authority addressed elsewhere is refused in process, not only over http', function (): void {
    hardeningReady();
    $elsewhere = hardeningAuthority(['audience' => 'people-connector.somewhere-else']);

    // Before #185 the audience was the wire's question alone: an in-process
    // caller could hand the port an authority minted for another service
    // and be accepted. Both doors now ask it, with the same answer.
    expect(hardeningRefusalInProcess($elsewhere))->toBe(DelegatedAuthorityRefusal::WrongAudience)
        ->and(hardeningRefusalOverHttp(hardeningAuthority(['audience' => 'people-connector.somewhere-else'])))->toBe(DelegatedAuthorityRefusal::WrongAudience)
        ->and(DelegatedAuthorityRefusal::WrongAudience->reachableInProcess())->toBeTrue();
});

test('the in-process audience is the configured one', function (): void {
    hardeningReady();
    config()->set('people-connector.delegation.audience', 'people-connector.somewhere-else');

    expect(hardeningRefusalInProcess(hardeningAuthority()))->toBe(DelegatedAuthorityRefusal::WrongAudience)
        ->and(hardeningRefusalInProcess(hardeningAuthority(['audience' => 'people-connector.somewhere-else'])))->toBeNull();
});

test('a token minted for another tenant\'s subject is refused in the right tenant through both doors', function (): void {
    hardeningReady();

    // Tenant 42 mints for its employee; the holder presents it where tenant
    // 41 is current. The signature is fine, the audience is fine, the
    // operation is fine. Neither door may fall through to the subject.
    $foreign = fn () => hardeningAuthority(['tenantId' => HARDENING_TENANT + 1, 'subject' => 'employee:EMP-OF-42']);

    expect(hardeningRefusalInProcess($foreign()))->toBe(DelegatedAuthorityRefusal::WrongTenant)
        ->and(hardeningRefusalOverHttp($foreign()))->toBe(DelegatedAuthorityRefusal::WrongTenant)
        ->and(DB::table(HARDENING_SPENDS)->count())->toBe(0);
});
