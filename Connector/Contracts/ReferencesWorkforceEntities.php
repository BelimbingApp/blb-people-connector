<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;

/**
 * A model that carries columns pointing at workforce entities other than its
 * own identity and its owning company.
 *
 * The declaration is the whole mechanism: the merge rewrites what is
 * declared, and the isolation contract fails the suite when a `*_entity_id`
 * column exists on the table and is not declared here (or is declared and
 * does not exist). A list that has to be remembered is what
 * blb-people-connector#29 and #35 were; a declaration next to the column is
 * what a reviewer sees in the same diff that adds the column.
 */
interface ReferencesWorkforceEntities
{
    /** @return list<WorkforceReference> */
    public function workforceReferences(): array;
}
