# First-party People reconciliation

The co-located `blb-people` adapter declares `ReconcilesWorkforce` on the same
three directory capabilities it already publishes (`company_directory`,
`organization_directory`, `employee_directory`). No new PeopleCapability is
added; `docs/providers/capability-register.json` is unchanged.

`WorkforceReconciliationPort` is resolved only through `ProviderPortResolver`
with live `ProviderPortAuthorization` evidence. For each company in the
connection's scope it reads People's `ReadsWorkforceDirectory` (and
`ReadsWorkforcePositions`) and diffs those records against the connection's
current workforce projections. It reports:

- `missing_in_projection` — People has a record the connection has not projected
- `missing_in_provider` — a projection has no People counterpart
- `active_flag_mismatch` — both sides carry the reference with different `active`
- `stale_source_version` — both sides disagree on `source_version`

The port never writes projections or reconciliation-issue rows. Sibling
companies outside the authorized scope are not read; a tenant context that no
longer matches the authorizing evidence is refused before any People call.
