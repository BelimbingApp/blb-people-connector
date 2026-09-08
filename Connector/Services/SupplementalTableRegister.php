<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\SupplementalTableReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The supplemental tables: what People's Skills and Training modules record
 * about the workforce the connector projects, and which no connector operation
 * may touch.
 *
 * A skill assessment or a training participant is a fact about an employee,
 * not about the provider that first told us the employee exists. Retiring a
 * connection, replacing its provider, rolling that replacement back and
 * purging aged connector rows all leave these tables byte-for-byte alone, and
 * this register is the one place that says which tables that promise covers.
 *
 * The list comes from the schema, not from a hand-written inventory: a table a
 * People migration adds tomorrow is covered the moment it exists, and nobody
 * has to remember to name it here. The prefixes are the boundary; a table
 * outside them is either connector-owned (DomainModels decides) or nobody's
 * business here. Not final so a test can stand in a sentinel and prove the
 * register is not read before authorization.
 */
class SupplementalTableRegister
{
    /** @var list<string> */
    public const PREFIXES = ['people_connector_skill_', 'people_connector_training_', 'people_training_'];

    public const RETENTION = 'indefinite';

    /**
     * Every supplemental table currently mounted, sorted. Empty when neither
     * People module is mounted: the register describes what is there, and an
     * absent module has no rows to protect.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(null, false),
            fn (string $table): bool => $this->isSupplemental($table),
        ));
        sort($tables);

        return $tables;
    }

    public function isSupplemental(string $table): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One entry per register table with the acting tenant's row count: never
     * purged, not bound to a connection, retention indefinite by construction.
     *
     * @return array<string, SupplementalTableReport> keyed by table name
     */
    public function report(int $tenantId): array
    {
        $entries = [];

        foreach ($this->tables() as $table) {
            $entries[$table] = new SupplementalTableReport(
                $table,
                DB::table($table)->where('tenant_id', $tenantId)->count(),
            );
        }

        return $entries;
    }
}
