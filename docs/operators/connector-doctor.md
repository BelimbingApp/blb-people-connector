# Connector doctor

Run all tenant-scoped connector health checks as a named operator:

```bash
php artisan connector:doctor --tenant=7 --as=42
```

Persist the same tenant-scoped checks for scheduled operational history:

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
and connection, two dead-letter rows (#271), plus two webhook ledger rows (#227): `webhook_stuck_reservations`,
red when a receipt older than five minutes has no delivery behind it (the
request died between reserving the delivery id and queuing the pass; its
retry is acknowledged as a duplicate, so this row is where the lost sync
shows), and the informational `webhook_duplicates`: deliveries acknowledged
as duplicates in the last seven days, never red; and `webhook_secret_overlap`
(#247), yellow while a connection's previous signing secret is still inside
its rotation overlap, with the count and the earliest expiry, and
`delegation_secret_overlap` (#262), the same shape for the deployment-wide
delegated-authority signing key: yellow with the expiry while a previous secret
is still accepted, red once that window has lapsed or when a previous secret is
configured without a usable expiry, which is a rotation nobody finished. It
names the expiry and never the key, and every tenant is told the same answer
because the key is not tenant-scoped; the procedure is in
[../security/delegated-authority.md](../security/delegated-authority.md).
The two dead-letter rows are red whenever something the connector gave up
on is still waiting for an operator. `webhook_dead_letters` counts this
tenant's deliveries whose retry budget ended (`dead_lettered`) and that no
replay points at yet, with the oldest `failed_at`; a delivery that failed for
the last time has left the queue, so `webhook_deliveries` alone would get
greener as it failed. Clear it with `connector:webhook:replay` (or
`connector:webhook:dead-letters --replay`); the replay keeps the dead-lettered
row and the count drops when the replay is created, its pass then watched by
`webhook_deliveries`. `sync_dead_letters` counts open parked feed pages
(reconciliation issues of kind `sync_dead_letter`) and the connections they
sit on; `reconciliation_drift` stays the total of every open issue and
includes them, so the new row is the subset that means a stuck feed rather
than a merge under review. No console command requeues a parked page: an
operator re-queues it from the reconciliation page, which goes through
`DeadLetterService::requeue()` with a review reference and resolves the issue
(see the `sync_dead_letter` row of [reconciliation-runbook.md](reconciliation-runbook.md)).

`connection_maintenance` (#264) is yellow with the count of connections
inside a planned maintenance window and the latest `maintenance_until`, green
with count 0 otherwise, never red: the pause is an operator's decision, see
[connection-maintenance.md](connection-maintenance.md).

Credential expiry (#296) is one row per **active** connection,
`provider_credential_expiry:<connection id>`: red when no usable provider
credential exists for the connection right now (expired, revoked, or never
issued), yellow when the latest usable one expires inside
`people-connector.doctor.credential_warning_days`
(`PEOPLE_CONNECTOR_DOCTOR_CREDENTIAL_WARNING_DAYS`, default 14), green
otherwise. The detail names the credential id, key id and expiry and never the
secret reference. Inactive and retired connections have no row. The action for
red or yellow is to issue a replacement through the credential store's
`ProviderCredentialStore::rotate()` path (which revokes the previous
credential as it issues the new one); `connector:webhook:secret:rotate` is the
webhook signing secret, not this. Because `--record` writes one snapshot per
row, `--alert` covers expiry with no further configuration, keyed by the same
`provider_credential_expiry:<id>` name.
Yellow does not fail the doctor; only red does. A provider without an active connection is red because its
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
