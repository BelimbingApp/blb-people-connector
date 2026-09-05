<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/**
 * Why a delegated authority was refused.
 *
 * A fixed set of codes, and codes only. Naming the refusal is not the same as
 * naming what was refused: an operator needs to know which check rejected them,
 * and nobody needs the tenant id or operation echoed back to prove it —
 * docs/contracts/diagnostic-privacy.md draws that line, and this is the side of
 * it a reason code sits on.
 *
 * The first four can only happen to a token, so only the HTTP path can raise
 * them. The last three are the backend recheck, which both transports run and
 * must answer identically.
 */
enum DelegatedAuthorityRefusal: string
{
    case Malformed = 'malformed';
    case Unsigned = 'unsigned';
    case WrongAudience = 'wrong_audience';
    case Unconfigured = 'unconfigured';
    case Expired = 'expired';
    case WrongTenant = 'wrong_tenant';
    case WrongOperation = 'wrong_operation';

    /** Whether an in-process caller could ever meet this refusal. */
    public function reachableInProcess(): bool
    {
        return match ($this) {
            self::Expired, self::WrongTenant, self::WrongOperation => true,
            default => false,
        };
    }
}
