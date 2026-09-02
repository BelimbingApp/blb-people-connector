<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class TenantOwnedModel extends Model
{
    protected $guarded = [];

    public function scopeForTenant(Builder $query, int $tenantId): void
    {
        $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}
