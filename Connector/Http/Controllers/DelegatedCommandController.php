<?php

namespace App\Domains\PeopleConnector\Connector\Http\Controllers;

use App\Domains\PeopleConnector\Connector\Contracts\AcceptsDelegatedCommands;
use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;
use App\Domains\PeopleConnector\Connector\Services\DelegatedAuthoritySigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verify a delegated authority off the wire and hand it to the same port an
 * in-process caller uses.
 *
 * It decides nothing. Everything it could decide differently from the
 * in-process path is a way for the two to drift apart, and the parity test
 * exists because that drift is the risk this whole boundary is guarding.
 *
 * No route is registered. Exposing this is a deployment decision with its own
 * exposure to argue about, and it belongs to whoever makes it — not to the
 * class being available.
 */
final class DelegatedCommandController
{
    public const AUTHORITY_HEADER = 'X-Belimbing-Delegated-Authority';

    public function __construct(
        private readonly DelegatedAuthoritySigner $signer,
        private readonly AcceptsDelegatedCommands $port,
    ) {}

    public function __invoke(Request $request, string $audience, string $operation): JsonResponse
    {
        $token = (string) $request->header(self::AUTHORITY_HEADER, '');

        try {
            $authority = $this->signer->verify($token, $audience);
            $this->port->accept($authority, $operation);
        } catch (DelegatedAuthorityException $refused) {
            // The typed code, never the exception text. Naming the refusal lets
            // an operator see which check rejected them; naming what was
            // refused would put the tenant and operation in the body, which
            // docs/contracts/diagnostic-privacy.md keeps out.
            return new JsonResponse([
                'refused' => ($refused->refusal ?? DelegatedAuthorityRefusal::Malformed)->value,
            ], 403);
        }

        return new JsonResponse(['accepted' => true], 200);
    }
}
