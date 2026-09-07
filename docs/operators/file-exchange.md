# File exchange discovery

Record what a scheduled provider export dropped for one connection, as a
named operator inside one tenant (#297):

```bash
php artisan connector:file-exchange:discover --tenant=7 --as=42 --connection=12
php artisan connector:file-exchange:discover --tenant=7 --as=42 --all
```

The directory is never on the command line. It is
`people-connector.file_exchange.inbound_root` (`PEOPLE_CONNECTOR_FILE_INBOUND_ROOT`)
plus one subdirectory named after the connection id, so connection 12 of the
deployment reads `$PEOPLE_CONNECTOR_FILE_INBOUND_ROOT/12`. A null root
disables discovery: the command says so and exits non-zero. `--all` walks the
directory of every active connection of the tenant; a directory for a
connection of another tenant is not walked. A missing connection directory
is an empty run, not an error.

Every regular file in the directory is hashed in a stream (`hash_file`) and
recorded in the [exchange ledger](../providers/hr2000-file-exchange.md#immutable-exchange-record)
with direction `import`, operation `discovered`, and the schema version
`Hr2000EmployeeCsvParser::SCHEMA_VERSION` when its first line is exactly that
candidate header, `null` otherwise. Only that first line is read; no row is
parsed, no file is moved or renamed, and no projection, identity or
reconciliation row changes. The ledger's duplicate rule applies: the same
bytes already recorded under the connection, under any name, are reported as
`already_recorded` and write nothing, so a rerun over an unchanged directory
records nothing.

The table lists `file, sha256 prefix, status, schema` per connection, with
`--json` for the same as a document. Statuses are `recorded`,
`already_recorded` and `refused`; a refusal names its reason
(`outside_inbound_root` for a symlink or other entry that resolves outside
the root, `not_a_regular_file`, `unreadable`) and makes the exit code
non-zero while the other files are still recorded. A connection directory
that itself resolves outside the root is refused before anything in it is
read. Neither the output nor the audit carries a path: files are named by
their basename and hash.

One `OperatorAudit` row per run (`file_exchange.discovered`) carries the
counts (`recorded`, `already_recorded`, `refused`, `schema_detected`) and
nothing else; each newly recorded file has its own `file_exchange.recorded`
row from the ledger. The operator needs the same capability as
`connector:doctor` (`people-connector.connection.list`) and must belong to
the tenant.

Scheduling is per tenant and per operator, because the run is audited as
that operator; add one line per tenant to the deployment's scheduler, for
example every 15 minutes with `--all`:

```cron
*/15 * * * * php /srv/belimbing/artisan connector:file-exchange:discover --tenant=7 --as=42 --all --json >> /var/log/belimbing/file-exchange-discover.log 2>&1
```

Discovery only records. Quarantine and archival of a recorded file, and the
import itself, are separate steps that never bypass the ledger.
