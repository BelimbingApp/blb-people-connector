# Connection maintenance window

Put one provider connection into a planned, bounded pause as a named
operator (#264), for example while the provider is being upgraded:

```bash
php artisan connector:connection:maintenance --tenant=7 --as=42 --connection=3 \
    --until=2026-09-08T02:00:00+08:00 --reason="provider upgrade"
```

End it early:

```bash
php artisan connector:connection:maintenance --tenant=7 --as=42 --connection=3 --end
```

A window is a pause, not a state change: the connection stays `active`, its
scheduler grants stay, and nothing already recorded is touched. Deactivation
and retirement remain what they are (docs/deployments/topologies.md); a
retired connection cannot enter a window.

`--until` must be in the future and at most 7 days away, so a forgotten
window lapses on its own instead of silencing a provider for good. A window
whose `maintenance_until` has passed is not maintenance; the next read that
notices it clears both columns, no purge is involved. `--reason` is at most
200 characters and is shown to operators, so keep credentials and provider
response fragments out of it.

## What is held

- `people-connector:sync` skips the connection with one line naming the
  window. No checkpoint moves and no page is read, so a half-migrated
  provider is never consulted and no dead-letter attempt is spent.
- A webhook-triggered pass (`RunIncrementalWorkforceSync`) ends `deferred`:
  the delivery is recorded, its `attempts` count is unchanged and no failure
  reason is written. The receiver itself keeps verifying, accepting and
  recording deliveries throughout.

## What continues

Everything that does not read the provider: the operator commands, the
doctor, reads of the workforce projections (stale, see below), reconciliation
work on records already held.

## Replaying held deliveries

After `--end`, or once the window has lapsed, re-send each `deferred` delivery
with `connector:webhook:replay` (docs/operators/webhook-replay.md); the replay
is a new row naming the original in `replayed_from_id`. While the window still
holds, replay refuses with `in_maintenance` because the pass would only be
deferred again. Support bundles list deferred deliveries under
`deliveries_by_status`.

## Visibility

`connector:doctor` reports a `connection_maintenance` row: yellow with the
count of connections in a window and the latest `maintenance_until`, green
with count 0 otherwise, never red, so the doctor's exit code is unaffected.
A freshness breach on a connection in maintenance is reported as
`stale (maintenance)` and raises no new reconciliation issue; an issue that
was already open stays as it is until the first pass after the window.

## Audit

Each start and each end records one operator audit row
(**Connection maintenance window changed**) naming the operator, the
connection, and the window and reason before and after. The summary carries
no credential material.

The command uses `people-connector.connection.manage`, is tenant-scoped, and
sees only the acting operator's tenant: an operator in tenant A cannot change
tenant B's connection, and an unknown or foreign connection id is refused
before anything is written.
