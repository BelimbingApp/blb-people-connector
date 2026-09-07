<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;

/**
 * What each side of a proposed cutover says one company holds.
 *
 * Mapping every identity proves the two providers can name the same people. It
 * does not prove they hold the same *number* of them: a target that has never
 * been told about an organization unit, or that carries a position the source
 * retired, maps perfectly and still arrives short or long. The counts are the
 * only thing that catches that, and they are reported per company because a
 * tenant's companies are cut over as one and a shortfall in one of them is not
 * softened by a surplus in another.
 */
final readonly class CutoverCountRow
{
    public function __construct(
        public int $companyEntityId,
        public WorkforceResourceType $resourceType,
        public int $sourceCount,
        public int $targetCount,
    ) {}

    public function matches(): bool
    {
        return $this->sourceCount === $this->targetCount;
    }
}
