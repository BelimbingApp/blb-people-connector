# Connection residue

After a connection is retired (`ConnectionRetirementService`, #180), its
identities, checkpoints, deliveries, credentials and file-exchange rows remain
as history. This command lists that residue for one retired connection so an
operator can deliberately retain or remove it:

```bash
php artisan connector:connection:residue 42 --tenant=7 --as=91
```

`--tenant` is required (`TenantScopedCommand`). The command uses
`people-connector.connection.list`, sees only the acting operator's tenant, and
writes exactly one `connection.residue_reported` operator audit row. It exits
non-zero while any flag still needs a decision. `--json` emits the same rows.

Active and inactive connections are refused: residue is a post-retirement
inventory, not a live health check.

## Columns

| Column | Meaning |
| --- | --- |
| Table | Connector-owned table still bound to the connection |
| Rows | How many of that table's rows name the connection |
| Retention | Declared `people-connector.retention` entry: a positive day count, or `kept` when the policy is indefinite |
| Flag | Outstanding decision, or `no` |

Checkpoint events are counted through the connection's sync checkpoints (they
carry `checkpoint_id`, not `connection_id`). File exchange rows bind via
`provider_connection_id`.

## Flags and actions

| Flag | Action |
| --- | --- |
| Current identity still unbound to a replacement | Remap the identity onto the replacement connection, or accept that it stays as retired history |
| Unrevoked credential | Revoke it (`ProviderCredentialStore::revoke`) so the secret reference cannot be spent |
| Open reconciliation issue | Resolve it, or accept it as history under the retention window measured from `resolved_at` |
| File exchange still recorded | Archive or quarantine the ledger row once the bytes are handled; a `recorded` row is unfinished work |

A table can have rows without a flag — for example webhook deliveries that are
already dead-lettered, identities that were remapped before retirement, or
identities closed by departure (`effective_to` set, no replacement). Those are
inventory, not open decisions.
