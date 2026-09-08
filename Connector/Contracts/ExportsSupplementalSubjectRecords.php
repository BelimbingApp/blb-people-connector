<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\People\Provider\Data\WorkforceSubject;

/**
 * A module that holds records about a workforce subject the connector does not
 * own (skill assessments, training participation, evidence, passports) and
 * hands them to the data-subject export (#308).
 *
 * The connector never names an implementation: a module registers one by
 * tagging the container with this interface,
 * `app()->tag([SkillsSubjectExporter::class], ExportsSupplementalSubjectRecords::class)`,
 * and the exporter calls every tagged instance after the operator is
 * authorized. What each returns is that module's business: it redacts by its
 * own rules and the connector copies rows, it does not interpret them.
 */
interface ExportsSupplementalSubjectRecords
{
    /** The module name the package and the audit row cite, e.g. `people.skills`. Unique per registration. */
    public function name(): string;

    /**
     * Whether a restore may hand this module's block back to it. A module that
     * says no still contributes to the export; the import records its block as
     * `not_restored` instead of re-emitting it.
     */
    public function restorable(): bool;

    /**
     * Rows about one subject, keyed by table name. The subject is the
     * connector's projection vocabulary: `stableId` is the workforce entity id
     * and `companyId` the owning workforce *company entity* id; the same two
     * ids are repeated as arguments so an implementation cannot read a wider
     * scope than the subject's tenant and company by mistake.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function sections(WorkforceSubject $subject, int $tenantId, int $companyEntityId): array;
}
