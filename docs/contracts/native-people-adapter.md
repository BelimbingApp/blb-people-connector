# Native People adapter boundary

`people-connector/native-people` is the connector-owned adapter for the
first-party `people/provider` module. The dependency is one-way: People
publishes transport-neutral records and readers without importing the optional
connector; this adapter maps those records into the canonical connector SDK.

The adapter ID is `blb-people`, matching the provider-qualified references
published by People. The current adapter is co-located and resolves the People
reader from the application container on every call. It never holds a tenant
context across Octane requests and never reads People models, services, or
tables outside the two published reader contracts.

## Declared capabilities

The published bootstrap and incremental pages carry company, organization,
employee, manager, and department-head facts. The adapter therefore declares
read-only company, organization, employee, and manager-hierarchy capabilities
through `BootstrapsWorkforce` and `ReadsWorkforceChanges`.

It does not declare Skill, training, payroll, attendance, leave, claims,
documents, SSO, writes, positions, reconciliation, or a user directory. A
confirmed user reference on an employee is identity evidence, not a complete
user-directory projection.

## Not yet a remote adapter

People publishes no authenticated remote route or service credential contract.
The co-located port must not be exposed over an invented endpoint or a human
session. When People publishes an authenticated controller over the same typed
wire records, a remote transport can be added and proven equivalent against
the same mapper and conformance fixtures.

Health remains `unknown` because People publishes no provider health contract.
Provider projection failures become compatibility errors, invalid opaque
cursors become validation errors without echoing the cursor, and unclassified
reader failures become retryable read failures. Connector authorization still
runs before port resolution; People remains responsible for ambient tenant
enforcement inside each reader call.
