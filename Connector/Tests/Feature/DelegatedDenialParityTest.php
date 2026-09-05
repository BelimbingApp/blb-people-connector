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
 * One dataset, both transports.
 *
 * DelegatedAuthorityTest already checks each refusal on its own. This asks the
 * only question that matters once there are two ways in: whether they answer
 * the same. Separate hand-written pairs drift; a shared dataset cannot, because
 * adding a case adds it to both paths at once.
 */

const PARITY_AUDIENCE = 'people-connector.first-party';

const PARITY_TENANT = 41;

const PARITY_OPERATION = 'employee.command.submit';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function parityReady(): void
{
    config()->set('people-connector.delegation.secret', str_repeat('k', 64));
    app(TenantContext::class)->set(PARITY_TENANT);
}

function parityAuthority(array $overrides = []): DelegatedAuthority
{
    $now = new DateTimeImmutable;

    return new DelegatedAuthority(
        subject: $overrides['subject'] ?? 'employee:EMP-1',
        tenantId: $overrides['tenantId'] ?? PARITY_TENANT,
        companyId: $overrides['companyId'] ?? 7,
        operation: $overrides['operation'] ?? PARITY_OPERATION,
        audience: $overrides['audience'] ?? PARITY_AUDIENCE,
        issuedAt: $overrides['issuedAt'] ?? $now,
        expiresAt: $overrides['expiresAt'] ?? $now->modify('+2 minutes'),
    );
}

/**
 * The shared denial dataset.
 *
 * Each case is a token the caller presents and the operation they ask for, so
 * both transports receive the identical input. Cases that can only be expressed
 * as a raw token — a forgery, a malformed string — are given as strings; the
 * rest are signed from an authority.
 *
 * @return array<string, array{token: string|DelegatedAuthority, operation: string, refusal: DelegatedAuthorityRefusal|null}>
 */
function parityCases(): array
{
    $now = new DateTimeImmutable;

    return [
        'accepted' => [
            'token' => parityAuthority(),
            'operation' => PARITY_OPERATION,
            'refusal' => null,
        ],
        'expired' => [
            'token' => parityAuthority([
                'issuedAt' => $now->modify('-10 minutes'),
                'expiresAt' => $now->modify('-5 minutes'),
            ]),
            'operation' => PARITY_OPERATION,
            'refusal' => DelegatedAuthorityRefusal::Expired,
        ],
        'wrong tenant' => [
            'token' => parityAuthority(['tenantId' => PARITY_TENANT + 1]),
            'operation' => PARITY_OPERATION,
            'refusal' => DelegatedAuthorityRefusal::WrongTenant,
        ],
        'wrong operation' => [
            'token' => parityAuthority(),
            'operation' => 'employee.command.cancel',
            'refusal' => DelegatedAuthorityRefusal::WrongOperation,
        ],
    ];
}

/**
 * @return array{inProcess: DelegatedAuthorityRefusal|null, http: DelegatedAuthorityRefusal|null}
 */
function parityDecide(DelegatedAuthority $authority, string $operation): array
{
    $signer = app(DelegatedAuthoritySigner::class);

    $inProcess = null;
    try {
        app(AcceptsDelegatedCommands::class)->accept($authority, $operation);
    } catch (DelegatedAuthorityException $refused) {
        $inProcess = $refused->refusal;
    }

    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, $signer->sign($authority));
    $response = app(DelegatedCommandController::class)($request, PARITY_AUDIENCE, $operation);
    $body = $response->getData(true);
    $http = $response->getStatusCode() === 200
        ? null
        : DelegatedAuthorityRefusal::from((string) $body['refused']);

    return ['inProcess' => $inProcess, 'http' => $http];
}

test('every denial in the shared dataset is answered identically by both transports', function (string $case): void {
    parityReady();
    $fixture = parityCases()[$case];
    $decisions = parityDecide($fixture['token'], $fixture['operation']);

    // The failure names the path, so a divergence says which side moved rather
    // than only that the two disagree.
    expect($decisions['inProcess'])->toBe(
        $fixture['refusal'],
        "in-process path answered differently from the dataset for [{$case}]",
    );
    expect($decisions['http'])->toBe(
        $fixture['refusal'],
        "http path answered differently from the dataset for [{$case}]",
    );
})->with(['accepted', 'expired', 'wrong tenant', 'wrong operation']);

test('a token this connector did not sign is refused by the transport that can see it', function (): void {
    parityReady();
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(DelegatedCommandController::AUTHORITY_HEADER, 'forged.payload');

    $response = app(DelegatedCommandController::class)($request, PARITY_AUDIENCE, PARITY_OPERATION);

    // Not a parity case: an in-process caller never had a token to forge. The
    // dataset holds the refusals both paths can reach; this one belongs to the
    // wire alone, and saying so is better than pretending the paths are
    // symmetric where they are not.
    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['refused'])->toBe(DelegatedAuthorityRefusal::Unsigned->value);
});

test('an authority addressed elsewhere is refused by the transport that can see it', function (): void {
    parityReady();
    $signer = app(DelegatedAuthoritySigner::class);
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(
        DelegatedCommandController::AUTHORITY_HEADER,
        $signer->sign(parityAuthority(['audience' => 'people-connector.somewhere-else'])),
    );

    $response = app(DelegatedCommandController::class)($request, PARITY_AUDIENCE, PARITY_OPERATION);

    expect($response->getData(true)['refused'])->toBe(DelegatedAuthorityRefusal::WrongAudience->value);
});

test('the refusal body carries a code and never the values that were refused', function (): void {
    parityReady();
    $signer = app(DelegatedAuthoritySigner::class);
    $request = Request::create('/delegated', 'POST');
    $request->headers->set(
        DelegatedCommandController::AUTHORITY_HEADER,
        $signer->sign(parityAuthority(['tenantId' => 999_001])),
    );

    $response = app(DelegatedCommandController::class)($request, PARITY_AUDIENCE, PARITY_OPERATION);

    // Naming the refusal is not the same as naming what was refused. The code
    // is a fixed enum value; the tenant that was rejected stays out of it.
    expect($response->getContent())->not->toContain('999001')
        ->and($response->getData(true))->toBe(['refused' => DelegatedAuthorityRefusal::WrongTenant->value]);
});
