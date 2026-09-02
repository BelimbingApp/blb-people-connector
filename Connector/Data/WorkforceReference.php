<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

/**
 * One column on a model that points at a workforce entity of one type.
 *
 * Declared on the model so the company merge can derive every branch it
 * rewrites instead of keeping a list, and so the isolation contract can
 * fail when a table grows a workforce reference nobody declared.
 *
 * `hierarchy` marks a reference into the row's own kind — a unit's parent, an
 * employee's manager — which a merge must null rather than rewrite when the
 * survivor would otherwise point at itself.
 */
final class WorkforceReference
{
    public function __construct(
        public readonly string $column,
        public readonly WorkforceResourceType $type,
        public readonly bool $hierarchy = false,
    ) {
        if (! str_ends_with($column, '_entity_id')) {
            throw new \InvalidArgumentException("A workforce reference column is named *_entity_id; [{$column}] is not.");
        }
    }
}
