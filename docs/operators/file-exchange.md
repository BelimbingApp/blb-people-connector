# File-exchange ledger commands

List, quarantine and archive rows on a connection's file-exchange ledger
without reading or moving any file bytes (#285 / #263):

```bash
php artisan connector:file-exchange:list --tenant=7 --as=42 --connection=15
php artisan connector:file-exchange:list --tenant=7 --as=42 --connection=15 --status=quarantined --json
php artisan connector:file-exchange:list --tenant=7 --as=42 --connection=15 --since=2026-09-01T00:00:00Z

php artisan connector:file-exchange:quarantine 901 --tenant=7 --as=42 --reason='schema version not approved'
php artisan connector:file-exchange:archive 901 --tenant=7 --as=42
```

Each command is tenant-scoped (`--tenant` required) and runs as a named
operator (`--as`). Listing needs `people-connector.connection.list`;
quarantine and archive need `people-connector.connection.manage`. The
operator must belong to the bound tenant.

## Statuses

| Status | Meaning | Next step |
| --- | --- | --- |
| `recorded` | Bytes were hashed and the row exists; nothing has marked them unsafe or done. | Quarantine on schema/drift refusal, or archive after safe application. |
| `quarantined` | An operator (or a future automated gate) refused application; `status_reason` is the short line. | Fix the provider file or policy, then archive when the episode is closed. |
| `archived` | Final. The ledger will not change this row again. | None — a second archive or quarantine is refused. |

Quarantine reasons are at most 190 characters (the operator-audit string
bound), one line, and must not contain paths or line breaks. Quarantine and
archive each write one operator audit row (`file_exchange.quarantined` /
`file_exchange.archived`) in the same transaction as the status change.

## What the list shows

The table (and `--json` `rows`) carries id, file name, direction, operation,
the first 12 characters of the SHA-256, byte length, schema version, status
and recorded-at. It never prints the on-disk path, the file bytes, or the
evidence payload — those stay where the ledger left them.
