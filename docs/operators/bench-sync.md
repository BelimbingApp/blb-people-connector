# Workforce sync bench

Time the full workforce sync against a synthetic in-memory provider, as a
named operator, and tear everything down again (#254):

```bash
php artisan connector:bench:sync --tenant=7 --as=42 --employees=5000 --units=50 --runs=3
php artisan connector:bench:sync --tenant=7 --as=42 --employees=5000 --units=50 --runs=3 --json
```

The bench provisions a throwaway tenant-scoped connection for the provider id
`bench.synthetic`, hands a synthetic adapter (one company, M units, N
employees with deterministic ids) straight to the real `WorkforceSyncRunner`,
runs the bootstrap pass `--runs` times, and reports per run the wall time,
peak memory, pages, conflicts and the rows written to each projection table.
The first run writes 1 company, M units and N employees; every later run of
the same shape writes zero rows, which is the idempotence check. p50 and p95
are nearest-rank percentiles of the per-run wall times.

Afterwards the bench deletes what it wrote — projections, snapshots,
checkpoints and their events, reconciliation issues, identities, entities,
sync audit rows, the scheduler grants and the bench connection itself — and
compares the row count of every connector-owned table with the count taken
before provisioning. `teardown=restored` (or `teardown.restored: true` in
JSON) means they are identical; anything else exits non-zero with
`teardown_incomplete`. Another tenant's tables are never read or written.

The operator passed as `--as` needs `people-connector.connection.manage` in
the tenant; the sync passes themselves run as the bench connection's
scheduler principal, exactly as `people-connector:sync` does. The bench
never runs against a configured provider: the adapter is not looked up in the
provider registry, and a tenant whose tenant scope already has an active
connection is refused before anything is provisioned, because activating the
bench connection there would deactivate it. Refusals and failures are reason
codes, never exception text: `invalid_employees` (`--employees=0` or
missing), `invalid_units`, `invalid_runs`, `unauthorized`,
`provider_configured`, `bench_residue` (a `bench.synthetic` connection is
already present, from a bench whose teardown did not finish), `sync_failed`,
`page_corrupt`, `run_failed`, `teardown_incomplete`.

## Measured numbers

Measured on 2026-09-07 with the command lines above, PHP 8.5.6, a file-backed
SQLite 3.45 database on a 12th Gen Core i5-12400 workstation. These are
SQLite numbers on a developer machine: measure again on the target
PostgreSQL database before reading them as a cutover estimate.

| employees | units | runs | run 1 wall | later runs wall | p50 | p95 | peak memory | pages |
|-----------|-------|------|------------|-----------------|-----|-----|-------------|-------|
| 50 | 5 | 2 | 362 ms | 223 ms | 223 ms | 362 ms | 59 MB | 1 |
| 1000 | 20 | 3 | 8423 ms | 4779 ms, 4559 ms | 4779 ms | 8423 ms | 78 MB | 5 |
| 5000 | 50 | 3 | 48798 ms | 33324 ms, 33416 ms | 33416 ms | 48798 ms | 145 MB | 21 |

Every run above tore down to identical counts. The first run costs roughly
8 to 10 ms per employee on this setup and an idempotent re-run roughly
5 to 7 ms; both scale linearly with N. The CI-sized smoke (N=50, in
`BenchSyncCommandTest`) asserts p95 under 30 s, a bound chosen to survive a
slow runner rather than to describe the numbers.
