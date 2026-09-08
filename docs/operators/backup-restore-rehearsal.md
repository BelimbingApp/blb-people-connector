# Connector backup and restore rehearsal

`connector:backup:rehearse` proves that the connector's canonical DataShare
package restores into an empty, separately provisioned scratch database. It
reports each connector table's tenant row count before export, before restore,
and after restore, plus DataShare's redaction advisories. Any mismatch exits
non-zero.

The scratch database must have the same schema and the same tenant, company,
and operator identifiers as the source, but no connector-owned rows. Configure
its private connection URL outside source control:

```dotenv
PEOPLE_CONNECTOR_REHEARSAL_DATABASE_URL=postgresql://...
```

Then run the rehearsal as an operator granted the connector-management
capability (`people-connector.connection.manage`):

```bash
php artisan connector:backup:rehearse --tenant=42 --as=7
```

The connection URL is passed only in the environment of a fresh child process,
so the source process's cached DataShare identity and settings cannot leak into
the destination. The one-time offer secret is held in a mode-0600 handoff file
and deleted when the child exits.

DataShare packages are instance-level. Until the platform supports
tenant-filtered export and identity remapping, this command refuses a source
whose connector scope contains rows for another tenant. It also refuses a
scratch database containing any connector rows; a rehearsal never overwrites
an earlier run.
