# Tenant move dry run

Before moving a tenant's connector-owned data to another tenant (plan 1011),
see what the move would touch and whether anything blocks it:

```bash
php artisan connector:migrate:dry-run --tenant=7 --to=9 --as=42
```

The report lists the source tenant's rows per connector-owned table (the
tables the retention policy enumerates), the source identities whose
external id the target tenant already maps (compared and shown as hashes,
with both identity ids), webhook deliveries still in flight (queued, or
failed with a retry still pending under the delivery policy; a dead-lettered
delivery is terminal and is neither in flight nor a blocker, and is not what
the "dead letters" line counts) and parked feed pages (dead letters) still
open. Any collision, in-flight delivery or dead
letter is a blocker and the command exits non-zero. `--json` for scripts.

The operator must be admitted by both tenants: the move capability
(`people-connector.connection.manage`) is asked of the authorization service
under the source tenant and again under the target tenant; a refusal in
either stops the run before anything is read.

The table list stops at the supplemental register
(`SupplementalTableRegister`): the Skills and Training tables People mounts
(`people_connector_skill_*`, `people_connector_training_*`,
`people_training_*`) are provider-independent, are never purged and are not
bound to any connection, so a connection's retirement, a provider's
replacement or its rollback leaves every one of them with the same rows it
had. They appear in `people-connector:retention-report` under "Supplemental"
with indefinite retention, and a retention rule naming one is refused.

The dry run writes nothing, not even an operator audit row: a dry run that
changed a table would fail its own promise. The move itself is a later
1011 lane.
