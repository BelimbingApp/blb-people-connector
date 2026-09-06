# Connector doctor

Run all tenant-scoped connector health checks as a named operator:

```bash
php artisan connector:doctor --tenant=7 --as=42
```

The table reports adapter conformance for every configured provider, queued
webhook-triggered syncs older than one hour, open reconciliation drift, and
active identity mappings that no longer join to a compatible current entity
and connection. A provider without an active connection is red because its
ports cannot be exercised. Any red row makes the command exit non-zero. Use
`--json` for automation.

The command uses `people-connector.connection.list` and sees only the acting
operator's tenant. Adapter probes resolve their read/write ports as each
connection's scheduler principal, preserving the same capability and company
boundary as a normal synchronization. The shipped database queue is required
to attribute stale webhook jobs to a tenant; an opaque queue backend is
reported red rather than guessed healthy.

This command does not repair, retry, resolve, or delete anything.

To re-send one failed webhook delivery by id, see
[webhook-replay.md](webhook-replay.md).
