# Rotating a webhook signing secret

Generate a new signing secret for one connection, keeping the current one
verifying for an overlap window so the provider can switch without a hard
cut:

```bash
php artisan connector:webhook:secret:rotate 12 --overlap-minutes=60 --tenant=7 --as=42
```

Secrets live in `PEOPLE_CONNECTOR_WEBHOOK_SECRETS` (an environment JSON),
never in a table, so the command writes nothing but an operator audit row.
It prints the new secret **exactly once** and the connection's new entry
list: a `<new-secret>` placeholder first, then each current secret as a
`<fingerprint:xxxxxxxx>` placeholder with `expires_at` = now + overlap
(an entry that already had an earlier expiry keeps it). Paste the list into
the environment value, replacing each placeholder with the secret it stands
for, and deploy; then give the provider the new secret before the overlap
ends. `--overlap-minutes=0` drops the previous secrets at once.

The audit row (**Webhook signing secret rotated**) carries the operator,
the connection, the overlap window and the fingerprints (first 8 hex of
sha256) of the previous and new secrets, never a secret: the audit writer
refuses any key naming one.

The command refuses, exits non-zero and writes nothing when the connection
is not in the operator's tenant, when the operator is outside the tenant or
lacks `people-connector.connection.manage`, or when the overlap is not
between 0 and 43200 minutes. While any previous secret is inside its
overlap, `connector:doctor` shows `webhook_secret_overlap` yellow with the
earliest expiry; it clears when the entries expire and does not fail the
doctor.
