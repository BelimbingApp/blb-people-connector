<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException;

/**
 * Permission for exactly one write to change a row's company columns.
 *
 * Held by reference, never by value: Eloquent clones the base query on every
 * toBase() (applyScopes clones when a global scope exists, and every
 * CompanyOwned model has one), so a flag stored as a scalar would be copied
 * into the clone and the original would stay armed for a second write. One
 * object shared by original and clone means whichever of them writes first
 * consumes it for both. This is the difference from withoutCompanyScope() on a
 * relation, which covered every later query built from it.
 */
final class CompanyMoveGrant
{
    private ?string $reason;

    public function __construct(string $reason)
    {
        if (trim($reason) === '') {
            throw CompanyMoveRefusedException::emptyReason();
        }

        $this->reason = $reason;
    }

    public function isArmed(): bool
    {
        return $this->reason !== null;
    }

    /**
     * The reason, once. Null afterwards, for everyone holding this grant.
     */
    public function consume(): ?string
    {
        $reason = $this->reason;
        $this->reason = null;

        return $reason;
    }
}
