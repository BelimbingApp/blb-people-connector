<?php

use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use Illuminate\Database\Eloquent\Model;

/*
 * Self-contained: helpers are prefixed shareCoverage and live here.
 *
 * DataShareRoundTripTest already proves the export and restore behave. This
 * asks the question that test cannot: whether the list it exports is still the
 * list this domain owns. A round trip is only a guarantee about the tables it
 * was handed.
 */

const CONNECTOR_SHARE_SCOPE = 'app/Domains/PeopleConnector/Connector';

/** @return list<string> */
function shareCoverageOwnedTables(): array
{
    $tables = array_map(
        static fn (string $model): string => (new $model)->getTable(),
        array_filter(DomainModels::all(), static fn (string $model): bool => is_subclass_of($model, Model::class)),
    );
    $tables = array_values(array_unique($tables));
    sort($tables);

    return $tables;
}

/** @return list<string> */
function shareCoverageScopeTables(): array
{
    $tables = array_column(app(DataShareScopeCatalog::class)->scope(CONNECTOR_SHARE_SCOPE)->tables, 'table');
    sort($tables);

    return $tables;
}

test('the data share scope exports every table this domain still owns', function (): void {
    // A table the domain owns but the scope omits leaves a hole in every export
    // and every restore, and nothing else would notice: the round trip only
    // proves the tables it was handed.
    expect(array_diff(shareCoverageOwnedTables(), shareCoverageScopeTables()))->toBe([]);
});

test('the data share scope exports no table this domain no longer owns', function (): void {
    // The other direction, and the one R4 makes live: Skill and Training moved
    // out, so a scope still naming their tables would export another domain's
    // rows under this domain's name.
    expect(array_diff(shareCoverageScopeTables(), shareCoverageOwnedTables()))->toBe([]);
});
