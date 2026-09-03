<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;

/**
 * Every model in this domain that declares itself company-owned.
 *
 * Two things consume this list and both used to keep their own: the
 * isolation contract (so a new slice is enrolled by using the trait) and the
 * company merge (so a new slice's rows follow the survivor). The merge kept
 * its list by hand and it was three tables short by the time anyone looked
 * (blb-people-connector#29). One list, derived, is the fix for that class of
 * omission — not a longer hand-written one. Discovery lives in DomainModels.
 */
final class CompanyOwnedModels
{
    /**
     * @return list<class-string<Model>>
     */
    public static function all(): array
    {
        return array_values(array_filter(
            DomainModels::all(),
            fn (string $model): bool => in_array(CompanyOwned::class, class_uses_recursive($model), true),
        ));
    }

    /**
     * The models whose rows name their owning company directly in the given
     * column — the ones a company merge must rewrite. A model that *is* a
     * company (owner column is its own entity id) or that inherits ownership
     * through a parent (null owner column) is excluded: the first is retired
     * by the merge itself, the second follows its parent.
     *
     * @return list<class-string<Model>>
     */
    public static function owningCompanyThrough(string $column): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $model): bool => (new $model)->companyOwnerColumn() === $column,
        ));
    }
}
