<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\DelegatedAuthority;
use Illuminate\Support\Facades\DB;

/**
 * The consumption ledger a delegated authority is spent against (#185).
 *
 * One row per (tenant, jti). The unique key does the deciding: the insert
 * that lands is the spend, the one that does not is the replay. Asking first
 * and inserting second would leave a gap two concurrent presentations could
 * both walk through, so there is no read here at all.
 *
 * Rows outlive the token by a day and then fall under retention: a token
 * that has expired cannot be replayed whether or not its row is still here.
 */
final class DelegatedAuthorityLedger
{
    public const TABLE = 'people_connector_connector_delegated_spends';

    /** True when this presentation is the first; false when it was already spent. */
    public function spend(int $tenantId, DelegatedAuthority $authority): bool
    {
        $now = now()->toImmutable();

        return DB::table(self::TABLE)->insertOrIgnore([
            'tenant_id' => $tenantId,
            'jti' => $authority->id,
            'subject' => $authority->subject,
            'operation' => $authority->operation,
            'spent_at' => $now,
            'expires_at' => $authority->expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
