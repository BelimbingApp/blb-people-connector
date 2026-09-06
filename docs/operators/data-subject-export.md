# Data-subject export

Use the subject export when an authorised operator must answer a data-subject
access request for one connector workforce entity:

```bash
php artisan people-connector:subject-export 123 --tenant=7 --as=42
```

For automation, add `--json`. The response contains the protected-storage
path, SHA-256 digest, byte size, and per-table row counts. Transfer the file
through the approved secure channel; it contains personal data.

The package is deliberately narrower than an instance DataShare backup. Its
format is `belimbing-data-share/people-connector-subject/v1`, and
`import_policy` is `identity-history`. It contains only the named tenant's canonical entity, identities,
current projections, snapshots, reconciliation issues, and directly related
operator-audit rows. Credential references and payload hashes are replaced by
null at every nesting level.

For an authorised support import, move the package unchanged into the protected
incoming DataShare directory, then name its package id and a target connection:

```bash
php artisan connector:identity-import <package-id> --connection=8 --tenant=9 --as=42
```

The import deliberately replays only the canonical entity, external identity
mapping, and append-only snapshots. It rewrites every relational tenant,
connection, entity, and identity id for the target; current projections,
reconciliation issues, and source audit rows remain evidence rather than a
restore contract. The target connection must use the exported provider and
belong to the acting operator's tenant and company. If any exported external id
is already mapped there, or a relationship points outside the package, the
whole import is refused without writes. A successful import appends one target
operator-audit row and requires `people-connector.identity.import`.

The command fails closed when the entity is outside the current tenant, when
the operator lacks `people-connector.identity.export`, or when the subject is
linked to multiple platform companies and therefore needs an explicit scope
decision. Every successful export appends a redacted operator-audit record;
the package created by that run naturally contains only earlier audit history.
