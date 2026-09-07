# Connection health check

Ping every active connection's adapter in a tenant and compare what each
adapter declares with the capability evidence register:

```bash
php artisan connector:health:check --tenant=7 --as=42
```

For each active connection the table shows the adapter's health state
(healthy, degraded, unavailable, unknown; the adapter's message text is not
printed), the capabilities it declares, **unsupported declared**: declared
without evidence in the register, and **withdrawn**: verified in the register
but no longer declared. Use `--json` for automation.

The register is [docs/providers/capability-register.json](../providers/capability-register.json)
(override with `PEOPLE_CONNECTOR_CAPABILITY_REGISTER`); its rows follow the
prose evidence registers such as
[hr2000-capability-evidence.md](../providers/hr2000-capability-evidence.md).
A provider absent from the file has verified nothing, so everything it
declares is drift. A file naming an unknown capability is refused.

The command exits non-zero on any unsupported declared capability, on a
connection whose adapter is not registered, and on an adapter reporting
unavailable. Withdrawn capabilities and an unknown health state (HR2000
before discovery completes) are reported without blocking. It uses
`people-connector.connection.list`, sees only the acting operator's tenant,
and changes nothing.
