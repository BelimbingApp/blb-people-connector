<?php

namespace App\Domains\PeopleConnector\Skill\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Interim company axis for company-owned Skill tables, pending the shared
 * mechanism from blb-people-connector#6 / blb-people#21. Tenant scoping alone
 * is NOT isolation for these tables: always address rows through forOwner().
 */
trait CompanyOwned
{
    public function scopeForOwner(Builder $query, int $tenantId, int $companyEntityId): void
    {
        $query->where($this->qualifyColumn('tenant_id'), $tenantId)
            ->where($this->qualifyColumn('company_entity_id'), $companyEntityId);
    }
}
