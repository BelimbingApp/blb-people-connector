# Connector doctor

Run all tenant-scoped connector health checks as a named operator:

```bash
php artisan connector:doctor --tenant=7 --as=42
```

Persist the same four tenant-scoped checks for scheduled operational history:

```bash
php artisan connector:doctor --tenant=7 --as=42 --record
php artisan connector:doctor --tenant=7 --as=42 --history=7
```

History shows the latest snapshot per check inside the requested window. The
existing `people-connector:retention-purge` command removes snapshots older
than 30 days; another tenant's snapshots are never read or purged.

Alert on what the snapshots show (#257):

```bash
php artisan connector:doctor --tenant=7 --as=42 --alert
```

`--alert` records the run, then compares each check with the tenant's
previous snapshot. A check red on two consecutive snapshots sends one alert
naming the check, its count and detail, and the first red timestamp; a single
transient red stays quiet. Green after such an incident sends one recovery
alert. An incident is (tenant, check, first red at) and each of its two alerts
goes out once, so a rerun while still red repeats nothing. Alerts go to the
`--as` operator through the Laravel notification channel named by
`people-connector.doctor.alert_channel` (`PEOPLE_CONNECTOR_DOCTOR_ALERT_CHANNEL`,
for example `database` or `mail`); a null channel disables alerting with an
informational line, never an error. The exit code is still the doctor's own.
Sent alerts live in `people_connector_connector_doctor_alerts`, purged with
the snapshots after 30 days.

The table reports adapter conformance for every configured provider, queued
webhook-triggered syncs older than one hour, open reconciliation drift,
active identity mappings that no longer join to a compatible current entity
and connection, plus two webhook ledger rows (#227): `webhook_stuck_reservations`,
red when a receipt older than five minutes has no delivery behind it (the
request died between reserving the delivery id and queuing the pass; its
retry is acknowledged as a duplicate, so this row is where the lost sync
shows), and the informational `webhook_duplicates`: deliveries acknowledged
as duplicates in the last seven days, never red; and `webhook_secret_overlap`
(#247), yellow while a connection's previous signing secret is still inside
its rotation overlap, with the count and the earliest expiry. Yellow does not
fail the doctor; only red does. A provider without an active connection is red because its
ports cannot be exercised. Any red row makes the command exit non-zero. Use
`--json` for automation.

The command uses `people-connector.connection.list` and sees only the acting
operator's tenant. Adapter probes resolve their read/write ports as each
connection's scheduler principal, preserving the same capability and company
boundary as a normal synchronization. The shipped database queue is required
to attribute stale webhook jobs to a tenant; an opaque queue backend is
reported red rather than guessed healthy.

This command does not repair, retry, resolve, or delete anything. To see
which connector capabilities the acting operator holds before running any of
the operator commands, use `connector:operator:whoami --tenant=7 --as=42`
(`--json` for scripts): it prints the tenant, company and every declared
`people-connector.*` capability as allowed or denied by the authorization
service itself. It is read-only and never describes an operator of another
tenant (#235).

To re-send one failed webhook delivery by id, see
[webhook-replay.md](webhook-replay.md). To ping each adapter and report
capability drift against the evidence register, see
[connector-health-check.md](connector-health-check.md).
