# Support bundle

Collect what a vendor or platform ticket needs into one zip, with the
guarantee that no credential, secret, token or personal data leaves the
tenant:

```bash
php artisan connector:support:bundle --tenant=7 --since=7d --as=42
```

The zip holds JSON files: `doctor-history.json` (every doctor snapshot in
the window), `sync-runs.json` (per pass: connection, time, stream, pass
kind, pages, upserts, deactivations, refusals, duration, completed; never a
payload), `webhooks.json` (deliveries by status, dead-lettered deliveries,
receipts, duplicates skipped, stuck reservations, open parked pages),
`reconciliation.json` (open issues by kind, no subject identifiers),
`retention.json` (the retention review, or a note when the operator lacks
that capability), `versions.json` (connector, adapters, PHP, Laravel) and
`config.json`: the connector config with every secret-bearing key replaced
by `sha256:` and the first 8 hex of the value's hash. `manifest.json` names
the tenant, the window, who generated it, and the redaction rules that
fired: `secret_key` (key names a secret, token, password, credential, key,
authorization, cookie or private material), `private_key` (a PEM block),
`email`, `id_number` (nine or more digits, national ids and account
numbers).

`--since` takes `<n>d`, `<n>h` or `<n>m` and bounds doctor history, sync
runs, deliveries and receipts to the exact instant; `--out` chooses the
directory (default `storage/app/people-connector/support`). The command
prints the path and the byte size and writes one operator audit row
(**Support bundle written**) naming the file, its size and the rules that
fired. It uses `people-connector.connection.list`, sees only the acting
operator's tenant, and refuses before any file exists when the operator is
outside the tenant or lacks the capability.
