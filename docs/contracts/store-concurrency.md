# Connector store concurrency

**Status:** Current contract (BelimbingApp/blb-people-connector#12)
**Last Updated:** 2026-09-04

## What `lock: true` / `lockForUpdate()` means

Connector identity, connection, checkpoint, credential, projection, and reconciliation stores pass `lock: true` (or call `lockForUpdate()` directly) inside `DB::transaction()` so concurrent writers on **PostgreSQL** serialise on the connection / identity / entity rows they are about to mutate.

On **SQLite**, Laravel’s grammar compiles those locks to nothing (`SQLiteGrammar::compileLock()` returns an empty string). The call is inert. The arguments stay: they are the honest Postgres path and must not be read as “this driver row-locks.”

## SQLite production contract

Belimbing ships SQLite as a production driver (Windows self-hosted default; see platform `docs/plans/database-managed-db-setup.md` and `config/database.php`). For Connector stores the concurrency story on SQLite is therefore:

1. **WAL** journal mode and a **busy timeout** (platform default `busy_timeout = 5000`) — wait for writers holding the database lock; do not invent row-level serialisation.
2. **One attempt** — `DB::transaction()` is invoked without a retry count. Snapshot / unique conflicts surface as errors rather than being retried away.
3. **No driver-shaped test lie** — a green SQLite run does not prove `FOR UPDATE` serialisation. Tests that claim concurrent serialisation must run on PostgreSQL (or state explicitly which driver they prove).

## What this does not claim

- It does not claim SQLite row locking.
- It does not claim automatic retry under write-write conflicts.
- It does not remove `lock: true` from the stores — removing it would weaken PostgreSQL without improving SQLite.

If the sync executor later shows measured `SQLITE_BUSY` or unique-violation rates that one attempt cannot absorb, reopen #12 (decision `sqlite-tx-retry`) before adding retries.
